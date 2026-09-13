<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Media\ResolvedYoutubeMedia;
use App\Media\StoredObject;
use App\Media\ValidatedRemoteUrl;
use App\Media\ValidatedYoutubeUrl;
use App\Media\YoutubeUrlValidator;
use App\Queue\ClaimedJob;
use App\Queue\FetchAndProbeHandler;
use PHPUnit\Framework\TestCase;

final class FetchAndProbeHandlerTest extends TestCase
{
    public function testDownloadsStoresAndProbesADirectSource(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, ['sourceId' => 10, 'url' => 'https://cdn.example.test/video.mp4']);
        $storage = new HandlerStorage();
        $downloader = new HandlerDownloader(new StoredObject('imports/4/fresh.mp4', 44, hash('sha256', 'fresh')));
        $handler = new FetchAndProbeHandler($projects, $sources, $downloader, $storage, new HandlerProcessor(new MediaMetadata(126, 1920, 1080, 'h264', null, false)), new HandlerProcessingEffectGuard(new HandlerPdo()), 1024);

        $outcome = $handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame([['fetching', 20], ['probing', 70], ['ready', 100]], $projects->states);
        self::assertSame([10], $sources->storedIds);
        self::assertSame([10], $sources->readyIds);
        self::assertSame([], $storage->deleted);
        self::assertStringStartsWith('imports/4/', $downloader->objectKey);
    }

    public function testForwardsAiSchedulerThroughDownloadAndProbe(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, ['sourceId' => 10, 'url' => 'https://cdn.example.test/video.mp4']);
        $scheduler = new FetchRecordingAiPipelineScheduler();
        $handler = new FetchAndProbeHandler(
            $projects,
            $sources,
            new HandlerDownloader(new StoredObject('imports/4/fresh.mp4', 44, hash('sha256', 'fresh'))),
            new HandlerStorage(),
            new HandlerProcessor(new MediaMetadata(126, 1920, 1080, 'h264', null, false)),
            new HandlerProcessingEffectGuard(new HandlerPdo()),
            1024,
            $scheduler
        );

        $outcome = $handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame([[4, 10, 126]], $scheduler->calls);
        self::assertSame([['fetching', 20], ['probing', 70]], $projects->states);
    }

    public function testReadyDirectSourceReplayStillSchedulesAi(): void
    {
        $projects = new HandlerProjectRepository();
        $source = new ProjectSource(10, 4, 'local', 'imports/4/video.mp4', 'video/mp4');
        $sources = new HandlerSourceRepository($source);
        $sources->readyDetails = ['source' => $source, 'duration_seconds' => 126];
        $scheduler = new FetchRecordingAiPipelineScheduler();
        $handler = new FetchAndProbeHandler(
            $projects,
            $sources,
            new HandlerDownloader(null),
            new HandlerStorage(),
            new HandlerProcessor(null),
            new HandlerProcessingEffectGuard(new HandlerPdo()),
            1024,
            $scheduler
        );

        $outcome = $handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame([[4, 10, 126]], $scheduler->calls);
        self::assertSame([], $projects->states);
    }

    public function testMarksValidationFailuresAsPermanentWithoutDeletingExistingMedia(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, ['sourceId' => 10, 'url' => 'https://cdn.example.test/video.mp4']);
        $storage = new HandlerStorage();
        $handler = new FetchAndProbeHandler($projects, $sources, new HandlerDownloader(null, MediaValidationException::withCode('invalid_media_container')), $storage, new HandlerProcessor(null), new HandlerProcessingEffectGuard(new HandlerPdo()), 1024);

        $outcome = $handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('invalid_media', $outcome->code());
        self::assertSame([10], $sources->failedIds);
        self::assertSame([], $storage->deleted);
    }

    public function testReturnsRetryForANetworkTimeout(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, ['sourceId' => 10, 'url' => 'https://cdn.example.test/video.mp4']);
        $handler = new FetchAndProbeHandler($projects, $sources, new HandlerDownloader(null, MediaValidationException::withCode('remote_timeout')), new HandlerStorage(), new HandlerProcessor(null), new HandlerProcessingEffectGuard(new HandlerPdo()), 1024);

        $outcome = $handler->handle($this->job());

        self::assertSame('retry', $outcome->status());
        self::assertSame('network_timeout', $outcome->code());
        self::assertSame([], $sources->failedIds);
    }

    public function testDeletesOnlyTheNewDownloadWhenPersistingItFails(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, ['sourceId' => 10, 'url' => 'https://cdn.example.test/video.mp4']);
        $sources->throwOnMarkStored = true;
        $storage = new HandlerStorage();
        $handler = new FetchAndProbeHandler($projects, $sources, new HandlerDownloader(new StoredObject('imports/4/fresh.mp4', 44, hash('sha256', 'fresh'))), $storage, new HandlerProcessor(null), new HandlerProcessingEffectGuard(new HandlerPdo()), 1024);

        $outcome = $handler->handle($this->job());

        self::assertSame('retry', $outcome->status());
        self::assertSame('processing_persistence_failed', $outcome->code());
        self::assertSame(['imports/4/fresh.mp4'], $storage->deleted);
    }

    public function testResolvesAndDownloadsARecognizedYoutubeUrlAsMp4(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, [
            'sourceId' => 10,
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);
        $storage = new HandlerStorage();
        $downloader = new HandlerDownloader(new StoredObject('imports/4/fresh.mp4', 44, hash('sha256', 'fresh')));
        $resolver = new HandlerYoutubeResolver(new ResolvedYoutubeMedia(new ValidatedRemoteUrl(
            'https://rr1---sn.example.googlevideo.com/videoplayback?expire=1',
            'rr1---sn.example.googlevideo.com',
            ['8.8.8.8']
        )));
        $handler = new FetchAndProbeHandler(
            $projects,
            $sources,
            $downloader,
            $storage,
            new HandlerProcessor(new MediaMetadata(126, 1920, 1080, 'h264', 'aac', true)),
            new HandlerProcessingEffectGuard(new HandlerPdo()),
            1024,
            null,
            null,
            null,
            $resolver,
            new YoutubeUrlValidator()
        );

        $outcome = $handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame('dQw4w9WgXcQ', $resolver->resolvedVideoId);
        self::assertSame(1, $downloader->youtubeCalls);
        self::assertStringEndsWith('.mp4', $downloader->objectKey);
        self::assertSame([[10, 'video/mp4', 'mp4']], $sources->storedMetadata);
    }

    public function testReportsMissingYoutubeCapabilityInsteadOfBlamingTheVideo(): void
    {
        $handler = new FetchAndProbeHandler(
            new HandlerProjectRepository(),
            new HandlerSourceRepository(null, [
                'sourceId' => 10,
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ]),
            new HandlerDownloader(null),
            new HandlerStorage(),
            new HandlerProcessor(null),
            new HandlerProcessingEffectGuard(new HandlerPdo()),
            1024,
            null,
            null,
            null,
            null,
            new YoutubeUrlValidator()
        );

        $outcome = $handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('youtube_import_unavailable', $outcome->code());
    }

    public function testAppliesPlanDownloadAndStorageLimitsBeforePersistingUrl(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, ['sourceId' => 10, 'url' => 'https://cdn.example.test/video.mp4']);
        $downloader = new HandlerDownloader(new StoredObject('imports/4/fresh.mp4', 44, hash('sha256', 'fresh')));
        $quota = new HandlerQuota(50);
        $handler = new FetchAndProbeHandler($projects, $sources, $downloader, new HandlerStorage(), new HandlerProcessor(new MediaMetadata(126, 1920, 1080, 'h264', null, false)), new HandlerProcessingEffectGuard(new HandlerPdo()), 1024, null, $quota);

        $outcome = $handler->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame(50, $downloader->maxBytes);
        self::assertSame([['upload', 7, 44], ['storage', 7, 44]], $quota->calls);
    }

    public function testQuotaOverflowDeletesNewUrlObjectAndFailsPermanently(): void
    {
        $projects = new HandlerProjectRepository();
        $sources = new HandlerSourceRepository(null, ['sourceId' => 10, 'url' => 'https://cdn.example.test/video.mp4']);
        $storage = new HandlerStorage();
        $quota = new HandlerQuota(50, true);
        $handler = new FetchAndProbeHandler($projects, $sources, new HandlerDownloader(new StoredObject('imports/4/fresh.mp4', 44, hash('sha256', 'fresh'))), $storage, new HandlerProcessor(null), new HandlerProcessingEffectGuard(new HandlerPdo()), 1024, null, $quota);

        $outcome = $handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('storage_limit_exceeded', $outcome->code());
        self::assertSame(['imports/4/fresh.mp4'], $storage->deleted);
        self::assertSame([], $sources->storedIds);
    }

    private function job(): ClaimedJob
    {
        return new ClaimedJob(2, 'media', 'fetch_and_probe', 4, [], 'worker-test', str_repeat('b', 64), 1, 3);
    }

    /** @dataProvider youtubeFailures */
    public function testPreservesSpecificYoutubeFailureAndBoundedRetry(string $code, string $status): void
    {
        $sources = new HandlerSourceRepository(null, ['sourceId'=>10,'url'=>'https://www.youtube.com/watch?v=O1FZD5Zove0']);
        $resolver = new class($code) implements \App\Contracts\BudgetedYoutubeMediaResolver {
            public int $limit = 0;
            public function __construct(private string $code) {}
            public function resolve(ValidatedYoutubeUrl $url): ResolvedYoutubeMedia { throw new \LogicException('Budgeted path required'); }
            public function resolveWithinLimit(ValidatedYoutubeUrl $url, int $maxBytes): ResolvedYoutubeMedia {
                $this->limit=$maxBytes;throw MediaValidationException::withCode($this->code);
            }
        };
        $downloader = new HandlerDownloader(null);
        $handler = new FetchAndProbeHandler(new HandlerProjectRepository(),$sources,$downloader,new HandlerStorage(),new HandlerProcessor(null),new HandlerProcessingEffectGuard(new HandlerPdo()),1024,null,null,null,$resolver,new YoutubeUrlValidator());
        $outcome=$handler->handle($this->job());
        self::assertSame($status,$outcome->status());self::assertSame($code,$outcome->code());
        self::assertSame(1024,$resolver->limit);self::assertSame(0,$downloader->youtubeCalls);
        self::assertSame($status==='failed'?[10]:[],$sources->failedIds);
        if($status==='retry') {
            $last=new ClaimedJob(2,'media','fetch_and_probe',4,[],'worker-test',str_repeat('b',64),3,3);
            self::assertSame('failed',$handler->handle($last)->status());
        }
    }
    public static function youtubeFailures(): iterable
    {
        foreach(['youtube_response_invalid','youtube_metadata_limit','youtube_bot_challenge','youtube_age_restricted','youtube_region_restricted','youtube_private_video','youtube_login_required','youtube_format_unavailable','media_too_large'] as $code)yield [$code,'failed'];
        yield ['youtube_rate_limited','retry'];yield ['youtube_network_failed','retry'];
    }
}

final class HandlerProjectRepository
{
    /** @var list<array{string, int}> */
    public array $states = [];

    public function ownerId(int $projectId): ?int
    {
        return $projectId === 4 ? 7 : null;
    }

    public function updateProcessingState(int $projectId, string $status, int $progress, ?string $errorCode = null, ?string $publicMessage = null): void
    {
        $this->states[] = [$status, $progress];
    }
}

final class HandlerSourceRepository
{
    public bool $throwOnMarkReady = false;
    public bool $throwOnMarkStored = false;
    /** @var list<int> */
    public array $readyIds = [];
    /** @var list<int> */
    public array $failedIds = [];
    /** @var list<int> */
    public array $storedIds = [];
    /** @var list<array{int,string,string}> */
    public array $storedMetadata = [];
    /** @var array{source: ProjectSource, duration_seconds: int}|null */
    public ?array $readyDetails = null;

    /** @param array{sourceId: int, url: string}|null $pending */
    public function __construct(private ?ProjectSource $stored, private ?array $pending = null)
    {
    }

    public function findForProject(int $projectId): ?ProjectSource
    {
        return $this->stored;
    }

    public function isReadyForProject(int $projectId): bool
    {
        return $this->readyDetails !== null;
    }

    /** @return array{source: ProjectSource, duration_seconds: int}|null */
    public function findReadyForAnalysis(int $sourceId, int $projectId): ?array
    {
        return $this->readyDetails;
    }

    /** @return array{sourceId: int, url: string}|null */
    public function findDirectUrlForProject(int $projectId): ?array
    {
        return $this->pending;
    }

    public function markStored(int $sourceId, StoredObject $object, string $mimeType, string $extension): void
    {
        if ($this->throwOnMarkStored) {
            throw new \PDOException('database unavailable');
        }
        $this->storedIds[] = $sourceId;
        $this->storedMetadata[] = [$sourceId, $mimeType, $extension];
        $this->stored = new ProjectSource($sourceId, 4, 'local', $object->objectKey(), $mimeType);
    }

    public function markReady(int $sourceId, MediaMetadata $metadata): void
    {
        if ($this->throwOnMarkReady) {
            throw new \PDOException('database unavailable');
        }
        $this->readyIds[] = $sourceId;
        if ($this->stored instanceof ProjectSource) {
            $this->readyDetails = ['source' => $this->stored, 'duration_seconds' => $metadata->durationSeconds()];
        }
    }

    public function markFailedForProject(int $projectId): void
    {
        $this->failedIds[] = $this->stored?->id() ?? ($this->pending['sourceId'] ?? 0);
    }

    public function markFailed(int $sourceId): void
    {
        $this->failedIds[] = $sourceId;
    }
}

final class HandlerProcessor implements \App\Contracts\MediaProcessor
{
    public function __construct(private ?MediaMetadata $metadata, private ?\Throwable $exception = null)
    {
    }

    public function inspect(ProjectSource $source): MediaMetadata
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }
        if ($this->metadata === null) {
            throw new \LogicException('No processor result was configured.');
        }
        return $this->metadata;
    }
}

final class HandlerDownloader
{
    public string $objectKey = '';
    public int $maxBytes = 0;
    public int $youtubeCalls = 0;

    public function __construct(private ?StoredObject $object, private ?\Throwable $exception = null)
    {
    }

    public function downloadStoredUrl(string $url, PrivateStorage $storage, string $objectKey, int $maxBytes): StoredObject
    {
        $this->objectKey = $objectKey;
        $this->maxBytes = $maxBytes;
        if ($this->exception !== null) {
            throw $this->exception;
        }
        if ($this->object === null) {
            throw new \LogicException('No downloader result was configured.');
        }
        return $this->object;
    }

    public function downloadYoutube(ResolvedYoutubeMedia $media, PrivateStorage $storage, string $objectKey, int $maxBytes): StoredObject
    {
        $this->youtubeCalls++;
        return $this->downloadStoredUrl($media->url()->url(), $storage, $objectKey, $maxBytes);
    }
}

final class HandlerYoutubeResolver implements \App\Contracts\YoutubeMediaResolver
{
    public string $resolvedVideoId = '';

    public function __construct(private ResolvedYoutubeMedia $media)
    {
    }

    public function resolve(ValidatedYoutubeUrl $url): ResolvedYoutubeMedia
    {
        $this->resolvedVideoId = $url->videoId();
        return $this->media;
    }
}

final class HandlerQuota
{
    /** @var list<array{string,int,int}> */
    public array $calls = [];

    public function __construct(private int $maxUploadBytes, private bool $rejectStorage = false)
    {
    }

    /** @return array<string,mixed> */
    public function snapshotForUser(int $userId): array
    {
        return ['plan' => ['features' => ['limits' => ['max_upload_bytes' => $this->maxUploadBytes]]]];
    }

    public function assertUploadBytesAllowed(int $userId, int $bytes): void
    {
        $this->calls[] = ['upload', $userId, $bytes];
    }

    public function assertAdditionalStorageAvailable(int $userId, int $bytes): void
    {
        $this->calls[] = ['storage', $userId, $bytes];
        if ($this->rejectStorage) {
            throw new \App\Plans\PlanLimitExceeded('storage_limit_exceeded', 100, 90, $bytes);
        }
    }
}

final class HandlerStorage implements PrivateStorage
{
    /** @var list<string> */
    public array $deleted = [];

    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject { throw new \LogicException('Not used.'); }
    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject { throw new \LogicException('Not used.'); }
    public function absolutePath(string $objectKey): string { return 'C:/private/' . $objectKey; }
    public function delete(string $objectKey): void { $this->deleted[] = $objectKey; }
}

final class HandlerPdo extends \PDO
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private bool $inTransaction = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        ++$this->begins;
        return $this->inTransaction = true;
    }

    public function commit(): bool
    {
        ++$this->commits;
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        ++$this->rollbacks;
        $this->inTransaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}

final class HandlerProcessingEffectGuard implements \App\Queue\ProcessingEffectGuard
{
    public function __construct(private HandlerPdo $pdo)
    {
    }

    public function apply(\App\Queue\ClaimedJob $job, callable $effect): bool
    {
        $this->pdo->beginTransaction();
        try {
            $effect();
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

final class FetchRecordingAiPipelineScheduler implements \App\Contracts\AiPipelineScheduler
{
    /** @var list<array{int, int, int}> */
    public array $calls = [];

    public function schedule(int $projectId, int $sourceId, int $durationSeconds): \App\Ai\AiAnalysisReceipt
    {
        $this->calls[] = [$projectId, $sourceId, $durationSeconds];

        return new \App\Ai\AiAnalysisReceipt(1, 1, 'queued', true);
    }
}
