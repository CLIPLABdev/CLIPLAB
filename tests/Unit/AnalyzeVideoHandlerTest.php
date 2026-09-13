<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\AnalysisResponseValidator;
use App\Ai\ViralClipPrompt;
use App\Contracts\JobDispatcher;
use App\Contracts\VideoAnalysisProvider;
use App\Credits\CreditReservation;
use App\Gemini\GeminiException;
use App\Gemini\GeminiFile;
use App\Media\ProjectSource;
use App\Queue\AnalyzeVideoHandler;
use App\Queue\ClaimedJob;
use App\Queue\ProcessingEffectGuard;
use PDOException;
use PHPUnit\Framework\TestCase;

final class AnalyzeVideoHandlerTest extends TestCase
{
    public function testRejectedTimelineIsRetriedWithBoundedSchemaAndCorrectionBeforePublishing(): void
    {
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'));
        $invalid = json_decode($this->validJson(), true);
        $invalid['clips'][0]['end_time'] = 130;
        $fixture->provider->generateResults = [json_encode($invalid), $this->validJson()];
        self::assertSame('deferred', $fixture->handler->handle($this->job())->status());
        self::assertSame(0, $fixture->jobs->countType('generate_clips'));
        self::assertSame('deferred', $fixture->handler->handle($this->job())->status());
        self::assertSame(1, $fixture->jobs->countType('generate_clips'));
        self::assertSame([], $fixture->credits->refundReasons);
        self::assertSame(126, $fixture->provider->requests[0]['schema']['properties']['clips']['items']['properties']['end_time']['maximum']);
        self::assertNotSame($fixture->provider->requests[0]['prompt'], $fixture->provider->requests[1]['prompt']);
        self::assertSame($this->validJson(), $fixture->analyses->row['validated_response_json']);
    }

    public function testTransientFailuresKeepTheRemoteFileThenStopAtConfiguredLimitAndRefundOnce(): void
    {
        $previous = getenv('GEMINI_ANALYSIS_MAX_ATTEMPTS');
        putenv('GEMINI_ANALYSIS_MAX_ATTEMPTS=');
        try {
            $config = require dirname(__DIR__, 2) . '/config/gemini.php';
            $limit = $config['analysis_max_attempts'] ?? 3;
            $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'));
            $fixture->provider->generateResults = array_fill(0, 6, GeminiException::withCode('ai_unavailable'));
            for ($attempt = 1; $attempt <= 5; ++$attempt) {
                self::assertSame('retry', $fixture->handler->handle($this->job('lease-' . $attempt, $attempt, $limit))->status());
                self::assertSame([], $fixture->credits->refundReasons);
            }
            self::assertSame('failed', $fixture->handler->handle($this->job('lease-6', 6, $limit))->status());
            self::assertSame('failed', $fixture->handler->handle($this->job('lease-7', 6, $limit))->status());
            self::assertSame(6, $fixture->provider->generateCalls);
            self::assertSame(['ai_unavailable'], $fixture->credits->refundReasons);
            self::assertSame('files/video-1', $fixture->analyses->row['gemini_file_name']);
            self::assertSame(0, $fixture->analyses->row['validation_attempts']);
        } finally {
            putenv($previous === false ? 'GEMINI_ANALYSIS_MAX_ATTEMPTS' : 'GEMINI_ANALYSIS_MAX_ATTEMPTS=' . $previous);
        }
    }

    public function testAnalysisConfigurationCannotAllowAnUnboundedProviderLoop(): void
    {
        $previous = getenv('GEMINI_ANALYSIS_MAX_ATTEMPTS');
        putenv('GEMINI_ANALYSIS_MAX_ATTEMPTS=99999');
        try {
            $config = require dirname(__DIR__, 2) . '/config/gemini.php';
            $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'));
            $fixture->provider->generateResults = array_fill(0, 9, GeminiException::withCode('ai_timeout'));
            $limit = $config['analysis_max_attempts'] ?? 3;
            for ($attempt = 1; $attempt <= 7; ++$attempt) {
                self::assertSame('retry', $fixture->handler->handle($this->job('lease-' . $attempt, $attempt, $limit))->status());
            }
            self::assertSame('failed', $fixture->handler->handle($this->job('lease-8', 8, $limit))->status());
            self::assertSame(['ai_timeout'], $fixture->credits->refundReasons);
        } finally {
            putenv($previous === false ? 'GEMINI_ANALYSIS_MAX_ATTEMPTS' : 'GEMINI_ANALYSIS_MAX_ATTEMPTS=' . $previous);
        }
    }

    public function testProviderFailureEmitsSafePhaseAndRetryHintEvenIfLoggerThrows(): void
    {
        $events = [];
        $observer = static function (array $event) use (&$events): void { $events[] = $event; throw new \RuntimeException('logger offline'); };
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'), null, $observer);
        $fixture->provider->generateResults = [GeminiException::withCode('ai_unavailable', [
            'http_status' => 503, 'curl_errno' => 0, 'failure_kind' => 'http', 'retry_after_seconds' => 120,
            'body' => 'secret-provider-response',
        ])];

        $outcome = $fixture->handler->handle($this->job('lease', 2, 6));

        self::assertSame('retry', $outcome->status());
        self::assertSame(120, $outcome->delaySeconds());
        self::assertSame([], $fixture->credits->refundReasons);
        self::assertSame([[
            'job_id' => 51, 'project_id' => 11, 'analysis_id' => 31,
            'attempt' => 2, 'max_attempts' => 6, 'phase' => 'generate', 'result_code' => 'ai_unavailable',
            'failure_kind' => 'http', 'http_status' => 503, 'curl_errno' => 0, 'retry_after_seconds' => 120,
        ]], $events);
    }

    public function testEachClaimPerformsAtMostOneProviderOperationAcrossUploadPollGenerateAndCleanup(): void
    {
        $fixture = $this->fixture();
        $fixture->provider->uploadResult = $this->file('PROCESSING');

        $upload = $fixture->handler->handle($this->job('lease-upload'));
        self::assertSame('deferred', $upload->status());
        self::assertSame(['upload'], $fixture->provider->operations);
        self::assertSame('waiting_file', $fixture->analyses->row['status']);

        $fixture->provider->getResult = $this->file('ACTIVE');
        $poll = $fixture->handler->handle($this->job('lease-poll'));
        self::assertSame('deferred', $poll->status());
        self::assertSame(['upload', 'get'], $fixture->provider->operations);
        self::assertSame('generating', $fixture->analyses->row['status']);

        $fixture->provider->generateResults[] = $this->validJson();
        $generate = $fixture->handler->handle($this->job('lease-generate'));
        self::assertSame('deferred', $generate->status());
        self::assertSame(['upload', 'get', 'generate'], $fixture->provider->operations);
        self::assertSame(1, $fixture->analyses->row['validation_attempts']);
        self::assertSame(1, $fixture->jobs->countType('generate_clips'));
        self::assertSame([
            'analysis_id' => 31,
            'reservation_id' => 41,
        ], $fixture->jobs->jobs[0]['payload']);
        self::assertSame(
            'ai:clips:31:' . ViralClipPrompt::VERSION,
            $fixture->jobs->jobs[0]['key']
        );

        $cleanup = $fixture->handler->handle($this->job('lease-cleanup'));
        self::assertSame('completed', $cleanup->status());
        self::assertSame(['upload', 'get', 'generate', 'delete'], $fixture->provider->operations);
    }

    public function testProcessingRemoteFileDefersWithoutCallingGenerate(): void
    {
        $fixture = $this->fixture(['status' => 'waiting_file'] + $this->remoteFields('PROCESSING'));
        $fixture->provider->getResult = $this->file('PROCESSING');

        $outcome = $fixture->handler->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertSame(['get'], $fixture->provider->operations);
        self::assertSame(0, $fixture->provider->generateCalls);
    }

    public function testInvalidResponsesUseExactlyTwoClaimsThenRefundAndNeverGenerateAgain(): void
    {
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'));
        $fixture->provider->generateResults = ['not-json', '{"still":"invalid"}'];

        $first = $fixture->handler->handle($this->job('lease-1'));
        self::assertSame('deferred', $first->status());
        self::assertSame(1, $fixture->analyses->row['validation_attempts']);
        self::assertSame([], $fixture->credits->refundReasons);

        $second = $fixture->handler->handle($this->job('lease-2'));
        self::assertSame('failed', $second->status());
        self::assertSame('ai_response_invalid', $second->code());
        self::assertSame(2, $fixture->analyses->row['validation_attempts']);
        self::assertSame(['ai_response_invalid'], $fixture->credits->refundReasons);
        self::assertSame('failed', $fixture->analyses->row['status']);

        $third = $fixture->handler->handle($this->job('lease-3'));
        self::assertSame('failed', $third->status());
        self::assertSame(2, $fixture->provider->generateCalls);
        self::assertSame(0, $fixture->jobs->countType('generate_clips'));
    }

    public function testAValidSecondGenerationPersistsAfterOneDurableInvalidAttempt(): void
    {
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'));
        $fixture->provider->generateResults = ['not-json', $this->validJson()];

        self::assertSame('deferred', $fixture->handler->handle($this->job('lease-1'))->status());
        $second = $fixture->handler->handle($this->job('lease-2'));

        self::assertSame('deferred', $second->status());
        self::assertSame(2, $fixture->analyses->row['validation_attempts']);
        self::assertSame($this->validJson(), $fixture->analyses->row['validated_response_json']);
        self::assertSame(2, $fixture->provider->generateCalls);
        self::assertSame(1, $fixture->jobs->countType('generate_clips'));
        self::assertSame([], $fixture->credits->refundReasons);
    }

    public function testProviderErrorsNeverIncrementValidationAndOnlyTerminalAttemptsRefund(): void
    {
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'));
        $fixture->provider->generateResults = [
            GeminiException::withCode('ai_timeout'),
            GeminiException::withCode('ai_timeout'),
        ];

        $retry = $fixture->handler->handle($this->job('lease-1', 1, 2));
        self::assertSame('retry', $retry->status());
        self::assertSame('ai_timeout', $retry->code());
        self::assertSame([], $fixture->credits->refundReasons);
        self::assertSame(0, $fixture->analyses->row['validation_attempts']);

        $failed = $fixture->handler->handle($this->job('lease-2', 2, 2));
        self::assertSame('failed', $failed->status());
        self::assertSame(['ai_timeout'], $fixture->credits->refundReasons);
        self::assertSame(0, $fixture->analyses->row['validation_attempts']);
    }

    public function testMissingConfigurationFailsTerminallyWithoutBootstrapOrAdditionalProviderCalls(): void
    {
        $fixture = $this->fixture();
        $fixture->provider->uploadResult = GeminiException::withCode('ai_unconfigured');

        $outcome = $fixture->handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('ai_unconfigured', $outcome->code());
        self::assertSame(['upload'], $fixture->provider->operations);
        self::assertSame(['ai_unconfigured'], $fixture->credits->refundReasons);
        self::assertSame('failed', $fixture->analyses->row['status']);
    }

    public function testFailedRemoteFileRefundsBeforeTerminalState(): void
    {
        $fixture = $this->fixture(['status' => 'waiting_file'] + $this->remoteFields('PROCESSING'));
        $fixture->provider->getResult = $this->file('FAILED');

        $outcome = $fixture->handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('ai_file_failed', $outcome->code());
        self::assertSame(['ai_file_failed'], $fixture->credits->refundReasons);
        self::assertSame(
            ['analysis_lock', 'refund', 'analysis_lock', 'analysis_failed', 'project_failed'],
            $fixture->order->events
        );
    }

    public function testMismatchedRefundReceiptCannotReachAnalysisOrProjectLocks(): void
    {
        $fixture = $this->fixture();
        $fixture->provider->uploadResult = GeminiException::withCode('ai_unconfigured');
        $fixture->credits->refundResult = new CreditReservation(99, 7, 11, 3, 'refunded');

        $outcome = $fixture->handler->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(['analysis_lock', 'refund'], $fixture->order->events);
        self::assertSame('uploading', $fixture->analyses->row['status']);
    }

    public function testStaleLeaseBeforeRemoteOrCheckpointDefersWithoutUnsafeEffects(): void
    {
        $before = $this->fixture();
        $before->effects->results = [false];
        $outcome = $before->handler->handle($this->job());
        self::assertSame('deferred', $outcome->status());
        self::assertSame([], $before->provider->operations);
        self::assertSame([], $before->credits->refundReasons);

        $after = $this->fixture();
        $after->effects->results = [true, false];
        $after->provider->uploadResult = $this->file('PROCESSING');
        $outcome = $after->handler->handle($this->job());
        self::assertSame('deferred', $outcome->status());
        self::assertSame(['upload'], $after->provider->operations);
        self::assertNull($after->analyses->row['gemini_file_name']);
    }

    public function testTypedCheckpointFailureDefersEvenOnLastAttempt(): void
    {
        $fixture = $this->fixture();
        $fixture->effects->throwOnCall = 1;

        $outcome = $fixture->handler->handle($this->job('lease-final', 3, 3));

        self::assertSame('deferred', $outcome->status());
        self::assertSame([], $fixture->provider->operations);
        self::assertSame([], $fixture->credits->refundReasons);
    }

    public function testTypedPostNetworkCheckpointFailureAlsoDefersOnLastAttempt(): void
    {
        $fixture = $this->fixture();
        $fixture->effects->throwOnCall = 2;
        $fixture->provider->uploadResult = $this->file('PROCESSING');

        $outcome = $fixture->handler->handle($this->job('lease-final', 3, 3));

        self::assertSame('deferred', $outcome->status());
        self::assertSame(['upload'], $fixture->provider->operations);
        self::assertNull($fixture->analyses->row['gemini_file_name']);
        self::assertSame([], $fixture->credits->refundReasons);
    }

    public function testExactPayloadAndCrossProjectLinksAreRequiredBeforeProviderUse(): void
    {
        foreach ([
            ['analysis_id' => 31, 'source_id' => 21, 'reservation_id' => 41, 'extra' => true],
            ['analysis_id' => 31, 'source_id' => 21, 'reservation_id' => 42],
            ['analysis_id' => '31', 'source_id' => 21, 'reservation_id' => 41],
        ] as $payload) {
            $fixture = $this->fixture();
            $outcome = $fixture->handler->handle($this->jobWithPayload($payload));
            self::assertSame('failed', $outcome->status());
            self::assertSame([], $fixture->provider->operations);
            self::assertSame(0, $fixture->jobs->countType('generate_clips'));
        }
    }

    public function testMismatchedSourceRefundsTheValidLogicalReservationWithoutProviderUse(): void
    {
        $fixture = $this->fixture();

        $outcome = $fixture->handler->handle($this->jobWithPayload([
            'analysis_id' => 31,
            'source_id' => 999,
            'reservation_id' => 41,
        ]));

        self::assertSame('failed', $outcome->status());
        self::assertSame('analysis_not_found', $outcome->code());
        self::assertSame(['analysis_not_found'], $fixture->credits->refundReasons);
        self::assertSame([], $fixture->provider->operations);
    }

    public function testCompletedAnalysisStillCleansRemoteFileInItsSeparateClaim(): void
    {
        $fixture = $this->fixture(['status' => 'completed', 'validated_response_json' => $this->validJson()] + $this->remoteFields('ACTIVE'));
        $fixture->credits->consume(41);

        $outcome = $fixture->handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame(['delete'], $fixture->provider->operations);
        self::assertSame(1, $fixture->effects->calls);
    }

    public function testCompletedAnalysisWithMismatchedSourceNeverCallsRemoteCleanup(): void
    {
        $fixture = $this->fixture(['status' => 'completed', 'validated_response_json' => $this->validJson()] + $this->remoteFields('ACTIVE'));
        $fixture->credits->consume(41);

        $outcome = $fixture->handler->handle($this->jobWithPayload([
            'analysis_id' => 31,
            'source_id' => 999,
            'reservation_id' => 41,
        ]));

        self::assertSame('completed', $outcome->status());
        self::assertSame([], $fixture->provider->operations);
        self::assertSame(0, $fixture->effects->calls);
    }

    public function testRefundedReservationOrFailedAnalysisNeverCallsProvider(): void
    {
        $refunded = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'));
        $refunded->credits->refund(41, 'analysis_failed');
        self::assertSame('failed', $refunded->handler->handle($this->job())->status());
        self::assertSame([], $refunded->provider->operations);

        $failed = $this->fixture(['status' => 'failed', 'error_code' => 'ai_timeout'] + $this->remoteFields('ACTIVE'));
        self::assertSame('failed', $failed->handler->handle($this->job())->status());
        self::assertSame([], $failed->provider->operations);
    }

    public function testCleanupFailureIsSanitizedAndDoesNotReopenValidatedAnalysis(): void
    {
        $fixture = $this->fixture(['status' => 'validating', 'validated_response_json' => $this->validJson()] + $this->remoteFields('ACTIVE'));
        $fixture->provider->deleteException = new \RuntimeException('provider detail must not escape');

        $outcome = $fixture->handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertNull($outcome->code());
        self::assertSame(['delete'], $fixture->provider->operations);
    }

    public function testFailedDownstreamAnalysisWithRefundStillCleansRemoteFile(): void
    {
        $fixture = $this->fixture([
            'status' => 'failed',
            'error_code' => 'processing_persistence_failed',
            'error_message' => 'Não foi possível salvar o processamento agora. Tente novamente.',
            'validated_response_json' => $this->validJson(),
        ] + $this->remoteFields('ACTIVE'));
        $fixture->credits->refund(41, 'processing_persistence_failed');

        $outcome = $fixture->handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame(['delete'], $fixture->provider->operations);
    }

    public function testRejectedGenerationsEmitOnlySafeReasonsOnceWithoutChangingTerminalReplay(): void
    {
        $events = [];
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'),
            static function (array $event) use (&$events): void { $events[] = $event; });
        $fixture->provider->generateResults = ['not-json SECRET https://private.invalid', '[]'];

        self::assertSame('deferred', $fixture->handler->handle($this->job())->status());
        self::assertSame('failed', $fixture->handler->handle($this->job())->status());
        self::assertSame('failed', $fixture->handler->handle($this->job())->status());

        self::assertSame([
            ['job_id' => 51, 'project_id' => 11, 'analysis_id' => 31, 'validation_attempt' => 1, 'reason_code' => 'invalid_json'],
            ['job_id' => 51, 'project_id' => 11, 'analysis_id' => 31, 'validation_attempt' => 2, 'reason_code' => 'invalid_shape'],
        ], $events);
        self::assertSame(2, $fixture->analyses->row['validation_attempts']);
        self::assertSame(2, $fixture->provider->generateCalls);
        self::assertSame(['ai_response_invalid'], $fixture->credits->refundReasons);
        self::assertSame(0, $fixture->jobs->countType('generate_clips'));
        self::assertNull($fixture->analyses->row['validated_response_json']);
    }

    public function testValidatedGenerationAndCleanupReplayEmitNoRejectionObservation(): void
    {
        $events = [];
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'),
            static function (array $event) use (&$events): void { $events[] = $event; });
        $fixture->provider->generateResults = [$this->validJson()];

        self::assertSame('deferred', $fixture->handler->handle($this->job())->status());
        self::assertSame('completed', $fixture->handler->handle($this->job())->status());
        self::assertSame([], $events);
        self::assertSame(1, $fixture->provider->generateCalls);
        self::assertSame(1, $fixture->analyses->row['validation_attempts']);
        self::assertSame($this->validJson(), $fixture->analyses->row['validated_response_json']);
        self::assertSame(1, $fixture->jobs->countType('generate_clips'));
        self::assertSame([], $fixture->credits->refundReasons);
    }

    public function testThrowingObserverCannotChangeValidationLimitOrRefund(): void
    {
        $calls = 0;
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'),
            static function (array $event) use (&$calls): void {
                ++$calls;
                throw new \RuntimeException('private logger failure');
            });
        $fixture->provider->generateResults = ['not-json', '[]'];

        self::assertSame('deferred', $fixture->handler->handle($this->job())->status());
        $failed = $fixture->handler->handle($this->job());
        self::assertSame('failed', $failed->status());
        self::assertSame('ai_response_invalid', $failed->code());
        self::assertSame('failed', $fixture->handler->handle($this->job())->status());
        self::assertSame(2, $calls);
        self::assertSame(2, $fixture->provider->generateCalls);
        self::assertSame(2, $fixture->analyses->row['validation_attempts']);
        self::assertSame(['ai_response_invalid'], $fixture->credits->refundReasons);
        self::assertSame(0, $fixture->jobs->countType('generate_clips'));
    }

    public function testRejectionObservationDoesNotBecomeDurableAttemptAfterLeaseLoss(): void
    {
        $events = [];
        $fixture = $this->fixture(['status' => 'generating'] + $this->remoteFields('ACTIVE'),
            static function (array $event) use (&$events): void { $events[] = $event; });
        $fixture->provider->generateResults = ['not-json'];
        $fixture->effects->results = [true, false];

        self::assertSame('deferred', $fixture->handler->handle($this->job())->status());
        self::assertCount(1, $events);
        self::assertSame(1, $events[0]['validation_attempt']);
        self::assertSame(0, $fixture->analyses->row['validation_attempts']);
        self::assertSame(1, $fixture->provider->generateCalls);
        self::assertSame([], $fixture->credits->refundReasons);
        self::assertSame(0, $fixture->jobs->countType('generate_clips'));
    }

    /** @param array<string, mixed> $analysis */
    private function fixture(array $analysis = [], ?callable $observer = null, ?callable $providerObserver = null): AnalyzeHandlerFixture
    {
        $order = new Task5EventOrder();
        $analyses = new Task5AnalysisRepository($order, $analysis);
        $sources = new Task5SourceRepository();
        $projects = new Task5ProjectRepository($order);
        $reservations = new Task5ReservationRepository();
        $credits = new Task5CreditService($reservations, $order);
        $jobs = new Task5JobDispatcher();
        $provider = new Task5VideoProvider();
        $effects = new Task5EffectGuard();
        $handler = new AnalyzeVideoHandler(
            $analyses,
            $sources,
            $projects,
            $reservations,
            $credits,
            $jobs,
            $provider,
            new ViralClipPrompt(),
            new AnalysisResponseValidator(),
            $effects,
            15,
            2,
            $observer,
            $providerObserver
        );

        return new AnalyzeHandlerFixture($handler, $analyses, $credits, $jobs, $provider, $effects, $order);
    }

    /** @param array<string, mixed> $payload */
    private function jobWithPayload(array $payload): ClaimedJob
    {
        return new ClaimedJob(51, 'media', 'analyze_video', 11, $payload, 'worker', str_repeat('a', 64), 1, 3);
    }

    private function job(string $lease = 'lease', int $attempts = 1, int $maxAttempts = 3): ClaimedJob
    {
        return new ClaimedJob(51, 'media', 'analyze_video', 11, [
            'analysis_id' => 31,
            'source_id' => 21,
            'reservation_id' => 41,
        ], 'worker', $lease, $attempts, $maxAttempts);
    }

    private function file(string $state): GeminiFile
    {
        return new GeminiFile('files/video-1', 'https://generativelanguage.googleapis.com/v1beta/files/video-1', 'video/mp4', $state);
    }

    /** @return array<string, string> */
    private function remoteFields(string $state): array
    {
        return [
            'gemini_file_name' => 'files/video-1',
            'gemini_file_uri' => 'https://generativelanguage.googleapis.com/v1beta/files/video-1',
            'gemini_file_mime' => 'video/mp4',
            'gemini_file_state' => $state,
        ];
    }

    private function validJson(): string
    {
        return '{"video_summary":"Resumo confiável","clips":[{"title":"Trecho principal","start_time":0,"end_time":20,"duration":20,"score":90,"reason":"Motivo","hook":"Gancho","category":"insight"}]}';
    }
}

final class AnalyzeHandlerFixture
{
    public function __construct(
        public AnalyzeVideoHandler $handler,
        public Task5AnalysisRepository $analyses,
        public Task5CreditService $credits,
        public Task5JobDispatcher $jobs,
        public Task5VideoProvider $provider,
        public Task5EffectGuard $effects,
        public Task5EventOrder $order
    ) {
    }
}

final class Task5EventOrder
{
    /** @var list<string> */
    public array $events = [];
}

final class Task5AnalysisRepository
{
    /** @var array<string, mixed> */
    public array $row;

    /** @param array<string, mixed> $overrides */
    public function __construct(private Task5EventOrder $order, array $overrides = [])
    {
        $this->row = $overrides + [
            'id' => 31,
            'project_id' => 11,
            'prompt_version' => ViralClipPrompt::VERSION,
            'model' => 'gemini-2.5-flash',
            'status' => 'queued',
            'gemini_file_name' => null,
            'gemini_file_uri' => null,
            'gemini_file_mime' => null,
            'gemini_file_state' => null,
            'video_summary' => null,
            'validated_response_json' => null,
            'validation_attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'duration_seconds' => 126,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findForProject(int $analysisId, int $projectId): ?array
    {
        return $analysisId === 31 && $projectId === 11 ? $this->row : null;
    }

    /** @return array<string, mixed>|null */
    public function findLockedForProject(int $analysisId, int $projectId): ?array
    {
        $this->order->events[] = 'analysis_lock';

        return $this->findForProject($analysisId, $projectId);
    }

    public function markUploading(int $analysisId): void
    {
        $this->row['status'] = 'uploading';
    }

    public function checkpointFile(int $analysisId, GeminiFile $file): void
    {
        $this->row['gemini_file_name'] = $file->name();
        $this->row['gemini_file_uri'] = $file->uri();
        $this->row['gemini_file_mime'] = $file->mimeType();
        $this->row['gemini_file_state'] = $file->state();
        $this->row['status'] = $file->state() === 'ACTIVE' ? 'generating' : 'waiting_file';
    }

    public function incrementValidationAttempts(int $analysisId): int
    {
        return ++$this->row['validation_attempts'];
    }

    public function storeValidatedResult(int $analysisId, string $json, \App\Ai\AiAnalysisResult $result): void
    {
        $this->row['validated_response_json'] = $json;
        $this->row['video_summary'] = $result->videoSummary();
        $this->row['status'] = 'validating';
    }

    public function markFailed(int $analysisId, string $code, string $message): void
    {
        $this->order->events[] = 'analysis_failed';
        $this->row['status'] = 'failed';
        $this->row['error_code'] = $code;
        $this->row['error_message'] = $message;
    }
}

final class Task5SourceRepository
{
    /** @return array{source: ProjectSource, duration_seconds: int}|null */
    public function findReadyForAnalysis(int $sourceId, int $projectId): ?array
    {
        if ($sourceId !== 21 || $projectId !== 11) {
            return null;
        }

        return [
            'source' => new ProjectSource(21, 11, 'local', 'imports/11/video.mp4', 'video/mp4'),
            'duration_seconds' => 126,
        ];
    }
}

final class Task5ProjectRepository
{
    public function __construct(private Task5EventOrder $order)
    {
    }

    public function ownerId(int $projectId): ?int
    {
        return $projectId === 11 ? 7 : null;
    }

    public function advanceProcessingState(int $projectId, string $status, ?string $code = null, ?string $message = null): void
    {
        if ($status === 'failed') {
            $this->order->events[] = 'project_failed';
        }
    }
}

final class Task5ReservationRepository
{
    public CreditReservation $reservation;

    public function __construct()
    {
        $this->reservation = new CreditReservation(41, 7, 11, 3, 'reserved');
    }

    public function findForAnalysis(int $reservationId, int $userId, int $projectId, int $units, string $promptVersion): ?CreditReservation
    {
        if ($reservationId !== $this->reservation->id()
            || $userId !== $this->reservation->userId()
            || $projectId !== $this->reservation->projectId()
            || $units !== $this->reservation->units()
            || $promptVersion !== ViralClipPrompt::VERSION
        ) {
            return null;
        }

        return $this->reservation;
    }

    public function findById(int $reservationId): ?CreditReservation
    {
        return $reservationId === $this->reservation->id() ? $this->reservation : null;
    }
}

final class Task5CreditService
{
    /** @var list<string> */
    public array $refundReasons = [];
    public ?CreditReservation $refundResult = null;

    public function __construct(private Task5ReservationRepository $reservations, private Task5EventOrder $order)
    {
    }

    public function costForDuration(int $durationSeconds): int
    {
        return 3;
    }

    public function refund(int $reservationId, string $reason): CreditReservation
    {
        $this->refundReasons[] = $reason;
        $this->order->events[] = 'refund';
        if ($this->refundResult !== null) {
            return $this->refundResult;
        }
        $old = $this->reservations->reservation;
        if ($old->status() === 'reserved') {
            $this->reservations->reservation = new CreditReservation($old->id(), $old->userId(), $old->projectId(), $old->units(), 'refunded');
        }

        return $this->reservations->reservation;
    }

    public function consume(int $reservationId): CreditReservation
    {
        $old = $this->reservations->reservation;
        $this->reservations->reservation = new CreditReservation(
            $old->id(),
            $old->userId(),
            $old->projectId(),
            $old->units(),
            'consumed'
        );

        return $this->reservations->reservation;
    }
}

final class Task5JobDispatcher implements JobDispatcher
{
    /** @var list<array{type: string, project_id: int, payload: array<string, mixed>, key: string}> */
    public array $jobs = [];

    public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
    {
        foreach ($this->jobs as $index => $job) {
            if ($job['key'] === $idempotencyKey) {
                return $index + 1;
            }
        }
        $this->jobs[] = ['type' => $type, 'project_id' => $projectId, 'payload' => $payload, 'key' => $idempotencyKey];

        return count($this->jobs);
    }

    public function countType(string $type): int
    {
        return count(array_filter($this->jobs, static fn (array $job): bool => $job['type'] === $type));
    }
}

final class Task5VideoProvider implements VideoAnalysisProvider
{
    public array $requests = [];
    /** @var list<string> */
    public array $operations = [];
    public int $generateCalls = 0;
    /** @var GeminiFile|\Throwable|null */
    public $uploadResult = null;
    /** @var GeminiFile|\Throwable|null */
    public $getResult = null;
    /** @var list<string|\Throwable> */
    public array $generateResults = [];
    public ?\Throwable $deleteException = null;

    public function upload(ProjectSource $source): GeminiFile
    {
        $this->operations[] = 'upload';
        return $this->result($this->uploadResult);
    }

    public function getFile(string $resourceName): GeminiFile
    {
        $this->operations[] = 'get';
        return $this->result($this->getResult);
    }

    public function generate(GeminiFile $file, string $prompt, array $responseSchema): string
    {
        $this->requests[] = ['prompt' => $prompt, 'schema' => $responseSchema];
        $this->operations[] = 'generate';
        ++$this->generateCalls;
        $result = array_shift($this->generateResults);
        if ($result instanceof \Throwable) {
            throw $result;
        }
        if (!is_string($result)) {
            throw new \LogicException('No fake generation result.');
        }

        return $result;
    }

    public function deleteFile(string $resourceName): void
    {
        $this->operations[] = 'delete';
        if ($this->deleteException !== null) {
            throw $this->deleteException;
        }
    }

    /** @param GeminiFile|\Throwable|null $result */
    private function result($result): GeminiFile
    {
        if ($result instanceof \Throwable) {
            throw $result;
        }
        if (!$result instanceof GeminiFile) {
            throw new \LogicException('No fake file result.');
        }

        return $result;
    }
}

final class Task5EffectGuard implements ProcessingEffectGuard
{
    /** @var list<bool> */
    public array $results = [];
    public int $calls = 0;
    public ?int $throwOnCall = null;

    public function apply(ClaimedJob $job, callable $effect): bool
    {
        ++$this->calls;
        if ($this->throwOnCall === $this->calls) {
            throw new PDOException('checkpoint unavailable');
        }
        $result = array_shift($this->results);
        if ($result === false) {
            return false;
        }
        $effect();

        return true;
    }
}
