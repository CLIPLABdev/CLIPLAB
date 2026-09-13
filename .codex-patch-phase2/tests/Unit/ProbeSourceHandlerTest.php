<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\MediaProcessor;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Process\ProcessExecutionException;
use App\Queue\ClaimedJob;
use App\Queue\ProbeSourceHandler;
use PHPUnit\Framework\TestCase;

final class ProbeSourceHandlerTest extends TestCase
{
    public function testTransitionsAStoredSourceThroughProbingToReadyInOnePersistenceTransaction(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(new ProjectSource(10, 4, 'local', 'imports/video.mp4', 'video/mp4'));
        $pdo = new HandlerPdo();
        $handler = new ProbeSourceHandler($projects, $sources, new HandlerProcessor(new MediaMetadata(126, 1920, 1080, 'h264', 'aac', true)), new HandlerProcessingEffectGuard($pdo));

        $outcome = $handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame([['probing', 70], ['ready', 100]], $projects->states);
        self::assertSame([10], $sources->readyIds);
        self::assertSame(2, $pdo->begins);
        self::assertSame(2, $pdo->commits);
        self::assertSame(0, $pdo->rollbacks);
    }

    public function testMarksInvalidMediaAsAPermanentFailure(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(new ProjectSource(10, 4, 'local', 'imports/video.mp4', 'video/mp4'));
        $handler = new ProbeSourceHandler($projects, $sources, new HandlerProcessor(null, \App\Exceptions\MediaValidationException::withCode('invalid_media_container')), new HandlerProcessingEffectGuard(new HandlerPdo()));

        $outcome = $handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('invalid_media', $outcome->code());
        self::assertSame([10], $sources->failedIds);
        self::assertSame(['failed', 100], $projects->states[1]);
    }

    public function testReturnsRetryWhenTheProcessorIsUnavailable(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(new ProjectSource(10, 4, 'local', 'imports/video.mp4', 'video/mp4'));
        $handler = new ProbeSourceHandler($projects, $sources, new HandlerProcessor(null, new ProcessExecutionException('process_unavailable')), new HandlerProcessingEffectGuard(new HandlerPdo()));

        $outcome = $handler->handle($this->job());

        self::assertSame('retry', $outcome->status());
        self::assertSame('processor_unavailable', $outcome->code());
        self::assertSame([], $sources->failedIds);
    }

    public function testRollsBackMetadataPersistenceAndRetriesTheJob(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(new ProjectSource(10, 4, 'local', 'imports/video.mp4', 'video/mp4'));
        $sources->throwOnMarkReady = true;
        $pdo = new HandlerPdo();
        $handler = new ProbeSourceHandler($projects, $sources, new HandlerProcessor(new MediaMetadata(126, 1920, 1080, 'h264', 'aac', true)), new HandlerProcessingEffectGuard($pdo));

        $outcome = $handler->handle($this->job());

        self::assertSame('retry', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame(2, $pdo->begins);
        self::assertSame(1, $pdo->rollbacks);
        self::assertSame(1, $pdo->commits);
    }

    public function testRetriesWhenTerminalFailurePersistenceDeadlocksBeforeTheLastAttempt(): void
    {
        $projects = new class {
            public function updateProcessingState(int $projectId, string $status, int $progress, ?string $errorCode = null, ?string $publicMessage = null): void
            {
                if ($status === 'failed') {
                    throw new \PDOException('Deadlock found when trying to get lock.');
                }
            }
        };
        $sources = new class {
            public function findForProject(int $projectId): ProjectSource
            {
                return new ProjectSource(10, 4, 'local', 'imports/video.mp4', 'video/mp4');
            }

            public function markFailedForProject(int $projectId): void
            {
            }

            public function markFailed(int $sourceId): void
            {
            }
        };
        $handler = new ProbeSourceHandler($projects, $sources, new DeadlockFailureProcessor(), new DeadlockFailureEffectGuard());

        $outcome = $handler->handle($this->job(1, 3));

        self::assertSame('retry', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
    }

    public function testDefersWhenTerminalFailurePersistenceDeadlocksOnTheLastAttempt(): void
    {
        $projects = new class {
            public function updateProcessingState(int $projectId, string $status, int $progress, ?string $errorCode = null, ?string $publicMessage = null): void
            {
                if ($status === 'failed') {
                    throw new \PDOException('Deadlock found when trying to get lock.');
                }
            }
        };
        $sources = new class {
            public function findForProject(int $projectId): ProjectSource
            {
                return new ProjectSource(10, 4, 'local', 'imports/video.mp4', 'video/mp4');
            }

            public function markFailedForProject(int $projectId): void
            {
            }

            public function markFailed(int $sourceId): void
            {
            }
        };
        $handler = new ProbeSourceHandler($projects, $sources, new DeadlockFailureProcessor(), new DeadlockFailureEffectGuard());

        $outcome = $handler->handle($this->job(3, 3));

        self::assertSame('deferred', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
    }

    private function job(int $attempts = 1, int $maxAttempts = 3): ClaimedJob
    {
        return new ClaimedJob(1, 'media', 'probe_source', 4, [], 'worker-test', str_repeat('a', 64), $attempts, $maxAttempts);
    }
}

final class DeadlockFailureProcessor implements MediaProcessor
{
    public function inspect(ProjectSource $source): MediaMetadata
    {
        throw \App\Exceptions\MediaValidationException::withCode('invalid_media_container');
    }
}

final class DeadlockFailureEffectGuard implements \App\Queue\ProcessingEffectGuard
{
    public function apply(\App\Queue\ClaimedJob $job, callable $effect): bool
    {
        $effect();
        return true;
    }
}