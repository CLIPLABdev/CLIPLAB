<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ClipAudioExtractor;
use App\Contracts\JobDispatcher;
use App\Contracts\TimedTranscriptionProvider;
use App\Media\ProjectSource;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Subtitles\Transcript;
use App\Media\Subtitles\TranscriptValidator;
use App\Queue\ClaimedJob;
use App\Queue\GenerateSubtitlesHandler;
use App\Queue\ProcessingEffectGuard;
use App\Repositories\ClipEditorRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ProjectRepository;
use App\Services\ClipRenderRequestService;
use App\Services\SourceDurationPreflight;
use PHPUnit\Framework\TestCase;
use PDO;
use Tests\Support\EofTestDatabase;
use Tests\Support\PreciseMediaProcessor;

final class MandatoryCaptionsPipelineTest extends TestCase
{
    public function testAutomaticRequestPersistsVisibleTranscriptBeforeRenderAndReadyRetrySkipsProvider(): void
    {
        [$pdo, $dispatcher, $provider, $editor] = $this->requestFixture();
        $service = $this->requestService($pdo, $dispatcher, $editor);

        $receipt = $service->request(41, 7, '0', '46');
        self::assertNotNull($receipt);
        self::assertSame('generate_subtitles', $dispatcher->calls[0]['type']);
        self::assertSame(['clip_id' => 41, 'render_revision' => 1], $dispatcher->calls[0]['payload']);

        $pending = $editor->snapshot(41, 1);
        self::assertSame('auto', $pending['mode']);
        self::assertSame('minimal', $pending['options']->toArray()['style']);
        self::assertSame('pending', $pending['track_status']);

        $handler = $this->handler($pdo, $editor, $provider, $dispatcher);
        $job = new ClaimedJob(100, 'media', 'generate_subtitles', 11,
            ['clip_id' => 41, 'render_revision' => 1], 'test-worker', 'lease-token', 1, 3);
        self::assertSame('completed', $handler->handle($job)->status());

        $ready = $editor->snapshot(41, 1);
        self::assertSame('ready', $ready['track_status']);
        self::assertNotNull($ready['transcript']);
        self::assertNotSame([], $ready['transcript']->cues());
        self::assertSame('render_clip', $dispatcher->calls[1]['type']);

        self::assertSame('completed', $handler->handle($job)->status());
        self::assertSame(1, $provider->calls);
    }

    public function testEmptyProviderTranscriptFailsClipAndNeverQueuesRender(): void
    {
        [$pdo, $dispatcher, $provider, $editor] = $this->requestFixture(
            TranscriptValidator::fromArray(['language' => 'und', 'cues' => []], 46000)
        );
        $service = $this->requestService($pdo, $dispatcher, $editor);
        $service->request(41, 7, '0', '46');

        $handler = $this->handler($pdo, $editor, $provider, $dispatcher);
        $job = new ClaimedJob(101, 'media', 'generate_subtitles', 11,
            ['clip_id' => 41, 'render_revision' => 1], 'test-worker', 'lease-token', 1, 3);
        $outcome = $handler->handle($job);

        self::assertSame('failed', $outcome->status());
        self::assertSame('subtitle_empty', $outcome->code());
        self::assertSame('failed', $editor->snapshot(41, 1)['track_status']);
        self::assertSame('failed', $pdo->query("SELECT status FROM clips WHERE id=41")->fetchColumn());
        self::assertCount(1, array_filter($dispatcher->calls, static fn (array $call): bool => $call['type'] === 'generate_subtitles'));
        self::assertCount(0, array_filter($dispatcher->calls, static fn (array $call): bool => $call['type'] === 'render_clip'));
    }

    private function requestFixture(?Transcript $result = null): array
    {
        $pdo = new EofTestDatabase();
        $pdo->exec("UPDATE clips SET status='suggested', render_revision=0, render_start_time=0, render_end_time=46 WHERE id=41");
        $editor = new ClipEditorRepository($pdo);
        $dispatcher = new MandatoryCaptionsDispatcher();
        $provider = new MandatoryCaptionsProvider($result);
        return [$pdo, $dispatcher, $provider, $editor];
    }

    private function requestService(PDO $pdo, JobDispatcher $dispatcher, ClipEditorRepository $editor): ClipRenderRequestService
    {
        $preflight = new SourceDurationPreflight($pdo, new \App\Repositories\ProjectSourceRepository($pdo), new PreciseMediaProcessor($pdo, 46000));
        return new ClipRenderRequestService($pdo, new ClipRepository($pdo), new ProjectRepository($pdo),
            new ClipRenderProfileRepository($pdo), new ReframePlanValidator(), $dispatcher, 180, $preflight, $editor);
    }

    private function handler(PDO $pdo, ClipEditorRepository $editor, MandatoryCaptionsProvider $provider, JobDispatcher $dispatcher): GenerateSubtitlesHandler
    {
        $audio = new class implements ClipAudioExtractor {
            public function extract(ProjectSource $source, float $start, float $duration): string { return 'test-wav'; }
        };
        return new GenerateSubtitlesHandler($editor, new ClipRepository($pdo), new ProjectRepository($pdo), $audio,
            $provider, new MandatoryCaptionsGuard($pdo), $dispatcher);
    }
}

final class MandatoryCaptionsDispatcher implements JobDispatcher
{
    public array $calls = [];
    public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
    { $this->calls[] = compact('type', 'projectId', 'payload', 'idempotencyKey'); return count($this->calls); }
}

final class MandatoryCaptionsProvider implements TimedTranscriptionProvider
{
    public int $calls = 0;
    public function __construct(private ?Transcript $result = null) {}
    public function transcribe(string $wavBytes, int $durationMs): Transcript
    {
        ++$this->calls;
        return $this->result ?? TranscriptValidator::fromArray(['language' => 'pt', 'cues' => [
            ['start_ms' => 0, 'end_ms' => 1200, 'text' => 'Legenda válida'],
        ]], $durationMs);
    }
}

final class MandatoryCaptionsGuard implements ProcessingEffectGuard
{
    public function __construct(private PDO $pdo) {}
    public function apply(ClaimedJob $job, callable $effect): bool
    {
        $this->pdo->beginTransaction();
        try { $effect(); $this->pdo->commit(); return true; }
        catch (\Throwable $error) { $this->pdo->rollBack(); throw $error; }
    }
}
