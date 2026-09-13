<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Ai\AiAnalysisResult;
use App\Ai\AnalysisResponseValidator;
use App\Ai\ViralClipPrompt;
use App\Core\Migrator;
use App\Credits\CreditReservation;
use App\Queue\GenerateClipsHandler;
use App\Queue\LeaseProcessingEffectGuard;
use App\Repositories\AiAnalysisRepository;
use App\Repositories\ClipRepository;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\AiPipelineStarter;
use App\Services\CreditReservationService;
use App\Services\DatabaseJobDispatcher;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class GenerateClipsHandlerTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $projectId;
    private int $sourceId;
    private CreditReservationService $credits;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        [$this->planId, $this->userId, $this->projectId, $this->sourceId] = $this->fixture();
        $this->credits = new CreditReservationService(
            $this->pdo,
            new CreditReservationRepository($this->pdo),
            new CreditTransactionRepository($this->pdo),
            1
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
        $this->pdo->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testMaterializesUniqueSuggestionsConsumesOnceAndCompletesAtomically(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        $handler = $this->handler(new ClipRepository($this->pdo));

        $first = $handler->handle($job);
        self::assertSame('completed', $first->status());
        self::assertSame('consumed', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('completed', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$analysisId]));
        self::assertSame(['status' => 'suggestions_ready', 'progress' => 100], $this->row('SELECT status, progress FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));

        (new ProcessingJobRepository($this->pdo))->complete($job);
        $replayJob = $this->dispatchAndClaim($analysisId, $reservationId, 'generate-replay');
        $second = $handler->handle($replayJob);
        self::assertSame('completed', $second->status());
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM credit_transactions WHERE reference_type = 'credit_reservation' AND reference_id = ? AND type = 'debit'", [$reservationId]));
    }

    public function testConsumePrecedesAnalysisAndClipLocksInsideTheFence(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        $order = new GenerateEventOrder();
        $handler = new GenerateClipsHandler(
            new GenerateOrderingAnalyses(new AiAnalysisRepository($this->pdo), $order),
            new GenerateOrderingClips(new ClipRepository($this->pdo), $order),
            new ProjectRepository($this->pdo),
            new CreditReservationRepository($this->pdo),
            new GenerateOrderingCredits($this->credits, $order),
            new AnalysisResponseValidator(),
            new LeaseProcessingEffectGuard($this->pdo)
        );

        $outcome = $handler->handle($job);

        self::assertSame('completed', $outcome->status());
        self::assertSame(['consume', 'analysis_lock', 'materialize'], $order->events);
        self::assertSame('consumed', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testDurationChangeDuringConsumeRollsBackBeforeMaterialization(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        $handler = new GenerateClipsHandler(
            new AiAnalysisRepository($this->pdo),
            new ClipRepository($this->pdo),
            new ProjectRepository($this->pdo),
            new CreditReservationRepository($this->pdo),
            new GenerateDurationChangingCredits($this->credits, $this->pdo, $this->projectId),
            new AnalysisResponseValidator(),
            new LeaseProcessingEffectGuard($this->pdo)
        );

        $outcome = $handler->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame('refunded', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('failed', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$analysisId]));
        self::assertSame(126, (int) $this->scalar('SELECT duration_seconds FROM project_sources WHERE project_id = ?', [$this->projectId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testCompletedReplayRejectsConflictingStoredClipContent(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        $handler = $this->handler(new ClipRepository($this->pdo));
        self::assertSame('completed', $handler->handle($job)->status());
        (new ProcessingJobRepository($this->pdo))->complete($job);
        $this->pdo->prepare("UPDATE clips SET title = 'Conteúdo adulterado' WHERE ai_analysis_id = ? AND suggestion_index = 0")->execute([$analysisId]);
        $replay = $this->dispatchAndClaim($analysisId, $reservationId, 'generate-conflict');

        $outcome = $handler->handle($replay);

        self::assertSame('failed', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
        self::assertSame('consumed', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
    }

    public function testCompletedReplayRejectsAnExtraStoredSuggestionIndex(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        $handler = $this->handler(new ClipRepository($this->pdo));
        self::assertSame('completed', $handler->handle($job)->status());
        (new ProcessingJobRepository($this->pdo))->complete($job);
        $this->pdo->prepare(
            "INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category)
             VALUES (?, ?, 2, 'Extra', 40, 60, 20, 70, 'Gancho', 'Motivo', 'other')"
        )->execute([$this->projectId, $analysisId]);
        $replay = $this->dispatchAndClaim($analysisId, $reservationId, 'generate-extra-index');

        $outcome = $handler->handle($replay);

        self::assertSame('failed', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testInvalidValidatedJsonIsRefundedBeforeFailureAndCreatesNoClips(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob('{"invalid":true}');

        $outcome = $this->handler(new ClipRepository($this->pdo))->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('analysis_not_found', $outcome->code());
        self::assertSame('refunded', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('failed', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$analysisId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testWrongLogicalReservationBindingCannotTouchUnrelatedReservedCredits(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        $this->pdo->prepare('UPDATE credit_reservations SET idempotency_key = ? WHERE id = ?')->execute([hash('sha256', 'wrong-logical-binding'), $reservationId]);

        $outcome = $this->handler(new ClipRepository($this->pdo))->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('analysis_not_found', $outcome->code());
        self::assertSame('reserved', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('failed', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$analysisId]));
        self::assertSame('failed', $this->scalar('SELECT status FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testRefundedReservationCanNeverGenerateClips(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        self::assertSame('refunded', $this->credits->refund($reservationId, 'analysis_failed')->status());

        $outcome = $this->handler(new ClipRepository($this->pdo))->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('analysis_not_found', $outcome->code());
        self::assertSame('refunded', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testPersistenceFailureOnLastAttemptRollsBackConsumeAndReconcilesRefundAndFailure(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson(), 3, 3);

        $outcome = $this->handler(new Task5FailingClipRepository($this->pdo))->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame('refunded', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('failed', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$analysisId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testPersistenceFailureBeforeLastAttemptRollsBackAndRetriesWithoutRefund(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson(), 1, 3);

        $outcome = $this->handler(new Task5FailingClipRepository($this->pdo))->handle($job);

        self::assertSame('retry', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame('reserved', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('validating', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$analysisId]));
        self::assertNotSame('failed', $this->scalar('SELECT status FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testExactPayloadIsRequiredBeforeTheCreditFence(): void
    {
        [$analysisId, $reservationId, $claimed] = $this->preparedJob($this->validJson());
        $job = new \App\Queue\ClaimedJob(
            $claimed->id(),
            $claimed->queueName(),
            $claimed->type(),
            $claimed->projectId(),
            [
            'analysis_id' => $analysisId,
            'reservation_id' => $reservationId,
            'validated_json' => 'must-not-be-in-payload',
            ],
            $claimed->workerId(),
            $claimed->leaseToken(),
            $claimed->attempts(),
            $claimed->maxAttempts()
        );

        $outcome = $this->handler(new ClipRepository($this->pdo))->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('analysis_not_found', $outcome->code());
        self::assertSame('reserved', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testExpiredLeaseBeforeConsumeDefersWithoutCreditOrClipEffects(): void
    {
        [$analysisId, $reservationId, $job] = $this->preparedJob($this->validJson());
        $this->pdo->prepare('UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([$job->id()]);

        $outcome = $this->handler(new ClipRepository($this->pdo))->handle($job);

        self::assertSame('deferred', $outcome->status());
        self::assertSame('reserved', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('validating', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$analysisId]));
        self::assertNotSame('failed', $this->scalar('SELECT status FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$analysisId]));
    }

    public function testAutomaticExportsUseRealRenderRequestsAndDoNotDuplicateOnReplay(): void
    {
        [$analysisId,$reservationId,$job]=$this->preparedJob($this->validJson());
        $this->pdo->prepare('UPDATE projects SET auto_render_requested=1 WHERE id=?')->execute([$this->projectId]);
        $render=new \App\Services\ClipRenderRequestService($this->pdo,new ClipRepository($this->pdo),
            new ProjectRepository($this->pdo),new \App\Repositories\ClipRenderProfileRepository($this->pdo),
            new \App\Media\Reframe\ReframePlanValidator(),new DatabaseJobDispatcher($this->pdo));
        $scheduler=new \App\Services\AutoRenderScheduler($this->pdo,[$render,'request']);
        $handler=$this->handler(new ClipRepository($this->pdo),[$scheduler,'schedule']);
        self::assertSame('completed',$handler->handle($job)->status());
        self::assertSame(2,(int)$this->scalar("SELECT COUNT(*) FROM processing_jobs WHERE project_id=? AND type='render_clip'",[$this->projectId]));
        self::assertSame(2,(int)$this->scalar("SELECT COUNT(*) FROM clips WHERE project_id=? AND status='queued'",[$this->projectId]));
        (new ProcessingJobRepository($this->pdo))->complete($job);
        // Keep this test's next claimed job the materialization replay, not its queued render.
        $this->pdo->prepare("UPDATE processing_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE project_id=? AND type='render_clip'")->execute([$this->projectId]);
        $replay=$this->dispatchAndClaim($analysisId,$reservationId,'auto-replay');
        self::assertSame('completed',$handler->handle($replay)->status());
        self::assertSame(2,(int)$this->scalar("SELECT COUNT(*) FROM processing_jobs WHERE project_id=? AND type='render_clip'",[$this->projectId]));
        self::assertSame('rendering',$this->scalar('SELECT status FROM projects WHERE id=?',[$this->projectId]));
    }

    public function testAutomaticExportFailureDoesNotUndoAnalysisClipsOrCharge(): void
    {
        [$analysisId,$reservationId,$job]=$this->preparedJob($this->validJson());
        $handler=$this->handler(new ClipRepository($this->pdo),static function (): void {
            throw new RuntimeException('automatic export unavailable');
        });

        $outcome=$handler->handle($job);

        self::assertSame('failed',$outcome->status());
        self::assertSame('processing_persistence_failed',$outcome->code());
        self::assertSame('consumed',$this->scalar('SELECT status FROM credit_reservations WHERE id=?',[$reservationId]));
        self::assertSame('completed',$this->scalar('SELECT status FROM ai_analyses WHERE id=?',[$analysisId]));
        self::assertSame('suggestions_ready',$this->scalar('SELECT status FROM projects WHERE id=?',[$this->projectId]));
        self::assertSame(2,(int)$this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id=?',[$analysisId]));
    }

    public function testAutomaticExportDatabaseFailureDefersWithoutUndoingCoreCommit(): void
    {
        [$analysisId,$reservationId,$job]=$this->preparedJob($this->validJson());
        $handler=$this->handler(new ClipRepository($this->pdo),static function (): void {
            throw new PDOException('automatic export database unavailable');
        });

        $outcome=$handler->handle($job);

        self::assertSame('deferred',$outcome->status());
        self::assertSame(15,$outcome->delaySeconds());
        self::assertSame('consumed',$this->scalar('SELECT status FROM credit_reservations WHERE id=?',[$reservationId]));
        self::assertSame('completed',$this->scalar('SELECT status FROM ai_analyses WHERE id=?',[$analysisId]));
        self::assertSame('suggestions_ready',$this->scalar('SELECT status FROM projects WHERE id=?',[$this->projectId]));
        self::assertSame(2,(int)$this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id=?',[$analysisId]));
    }

    private function handler(object $clips, ?callable $autoRender = null): GenerateClipsHandler
    {
        return new GenerateClipsHandler(
            new AiAnalysisRepository($this->pdo),
            $clips,
            new ProjectRepository($this->pdo),
            new CreditReservationRepository($this->pdo),
            $this->credits,
            new AnalysisResponseValidator(),
            new LeaseProcessingEffectGuard($this->pdo),
            $autoRender
        );
    }

    /** @return array{int, int, \App\Queue\ClaimedJob} */
    private function preparedJob(string $json, int $attempts = 1, int $maxAttempts = 3): array
    {
        $starter = new AiPipelineStarter(
            $this->pdo,
            new ProjectRepository($this->pdo),
            new ProjectSourceRepository($this->pdo),
            new AiAnalysisRepository($this->pdo),
            $this->credits,
            new DatabaseJobDispatcher($this->pdo, 'media', 3),
            ViralClipPrompt::VERSION,
            'gemini-2.5-flash'
        );
        $receipt = $starter->schedule($this->projectId, $this->sourceId, 126);
        self::assertNotNull($receipt->reservationId());
        $this->pdo->prepare("UPDATE ai_analyses SET status = 'validating', validated_response_json = ?, video_summary = 'Resumo confiável', validation_attempts = 1 WHERE id = ?")->execute([$json, $receipt->analysisId()]);
        $this->pdo->prepare("UPDATE processing_jobs SET status = 'completed', finished_at = UTC_TIMESTAMP() WHERE project_id = ?")->execute([$this->projectId]);
        $job = $this->dispatchAndClaim($receipt->analysisId(), (int) $receipt->reservationId(), 'generate-' . bin2hex(random_bytes(5)), null, $maxAttempts);
        if ($attempts > 1) {
            $this->pdo->prepare('UPDATE processing_jobs SET attempts = ? WHERE id = ?')->execute([$attempts - 1, $job->id()]);
            $this->pdo->prepare("UPDATE processing_jobs SET status = 'retry', worker_id = NULL, lease_token_hash = NULL, leased_until = NULL, available_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$job->id()]);
            $job = (new ProcessingJobRepository($this->pdo))->claimNext('media', 'generate-worker-final', 120);
            self::assertNotNull($job);
        }

        return [$receipt->analysisId(), (int) $receipt->reservationId(), $job];
    }

    /** @param array<string, mixed>|null $payload */
    private function dispatchAndClaim(int $analysisId, int $reservationId, string $key, ?array $payload = null, int $maxAttempts = 3): \App\Queue\ClaimedJob
    {
        (new DatabaseJobDispatcher($this->pdo, 'media', $maxAttempts))->dispatch('generate_clips', $this->projectId, $payload ?? [
            'analysis_id' => $analysisId,
            'reservation_id' => $reservationId,
        ], $key);
        $job = (new ProcessingJobRepository($this->pdo))->claimNext('media', 'generate-worker-' . bin2hex(random_bytes(3)), 120);
        self::assertNotNull($job);

        return $job;
    }

    /** @return array{int, int, int, int} */
    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')->execute(['generate-' . $suffix, 'Generate ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 10)')->execute(['Generate fixture', 'generate-' . $suffix . '@example.test', 'not-a-real-hash', $planId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (?, 'credit', 10, 10, 'test_fixture', 'Generate credits')")->execute([$userId]);
        $this->pdo->prepare("INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Generate project', 'ready', 70)")->execute([$userId]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes, duration_seconds, width, height, video_codec, has_audio, status) VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 126, 1920, 1080, 'h264', 0, 'ready')")->execute([$projectId, 'imports/' . $projectId . '/video.mp4']);
        $sourceId = (int) $this->pdo->lastInsertId();

        return [$planId, $userId, $projectId, $sourceId];
    }

    private function validJson(): string
    {
        return '{"video_summary":"Resumo confiável","clips":[{"title":"Primeiro","start_time":0,"end_time":20,"duration":20,"score":90,"reason":"Motivo A","hook":"Gancho A","category":"insight"},{"title":"Segundo","start_time":20,"end_time":40,"duration":20,"score":80,"reason":"Motivo B","hook":"Gancho B","category":"educational"}]}';
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params)
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /** @param list<mixed> $params @return array<string, mixed> */
    private function row(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}

final class Task5FailingClipRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<int> */
    public function materialize(int $analysisId, int $projectId, AiAnalysisResult $result): array
    {
        $clip = $result->clips()[0];
        $statement = $this->pdo->prepare(
            "INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $projectId,
            $analysisId,
            $clip->index(),
            $clip->title(),
            $clip->startTime(),
            $clip->endTime(),
            $clip->duration(),
            $clip->score(),
            $clip->hook(),
            $clip->reason(),
            $clip->category(),
        ]);

        throw new PDOException('simulated persistence failure');
    }
}

final class GenerateEventOrder
{
    /** @var list<string> */
    public array $events = [];
}

final class GenerateOrderingAnalyses
{
    public function __construct(private AiAnalysisRepository $inner, private GenerateEventOrder $order)
    {
    }

    /** @return array<string, mixed>|null */
    public function findForProject(int $analysisId, int $projectId): ?array
    {
        return $this->inner->findForProject($analysisId, $projectId);
    }

    /** @return array<string, mixed>|null */
    public function findLockedForProject(int $analysisId, int $projectId): ?array
    {
        $this->order->events[] = 'analysis_lock';

        return $this->inner->findLockedForProject($analysisId, $projectId);
    }

    public function markCompleted(int $analysisId): void
    {
        $this->inner->markCompleted($analysisId);
    }

    public function markFailed(int $analysisId, string $code, string $message): void
    {
        $this->inner->markFailed($analysisId, $code, $message);
    }
}

final class GenerateOrderingClips
{
    public function __construct(private ClipRepository $inner, private GenerateEventOrder $order)
    {
    }

    /** @return list<int> */
    public function materialize(int $analysisId, int $projectId, AiAnalysisResult $result): array
    {
        $this->order->events[] = 'materialize';

        return $this->inner->materialize($analysisId, $projectId, $result);
    }
}

final class GenerateOrderingCredits
{
    public function __construct(private CreditReservationService $inner, private GenerateEventOrder $order)
    {
    }

    public function costForDuration(int $durationSeconds): int
    {
        return $this->inner->costForDuration($durationSeconds);
    }

    public function consume(int $reservationId): CreditReservation
    {
        $this->order->events[] = 'consume';

        return $this->inner->consume($reservationId);
    }

    public function refund(int $reservationId, string $reason): CreditReservation
    {
        $this->order->events[] = 'refund';

        return $this->inner->refund($reservationId, $reason);
    }
}

final class GenerateDurationChangingCredits
{
    public function __construct(
        private CreditReservationService $inner,
        private PDO $pdo,
        private int $projectId
    ) {
    }

    public function costForDuration(int $durationSeconds): int
    {
        return $this->inner->costForDuration($durationSeconds);
    }

    public function consume(int $reservationId): CreditReservation
    {
        $consumed = $this->inner->consume($reservationId);
        $statement = $this->pdo->prepare('UPDATE project_sources SET duration_seconds = 125 WHERE project_id = ?');
        $statement->execute([$this->projectId]);

        return $consumed;
    }

    public function refund(int $reservationId, string $reason): CreditReservation
    {
        return $this->inner->refund($reservationId, $reason);
    }
}
