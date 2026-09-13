<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\MediaProcessor;
use App\Exceptions\MediaValidationException;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Queue\ClaimedJob;
use App\Queue\FetchAndProbeHandler;
use App\Queue\ProcessingEffectGuard;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceCleanupPdo;
use Tests\Support\SourceCleanupStorage;
use Tests\Support\SourceCleanupStoreFake;

require_once dirname(__DIR__).'/Support/SourceCleanupFakes.php';

final class FetchSourceCleanupTest extends TestCase
{
    private SourceCleanupStoreFake $cleanups;
    private SourceCleanupStorage $storage;
    private object $sources;
    private object $effects;
    private object $downloader;
    private FetchAndProbeHandler $handler;
    private ClaimedJob $job;

    protected function setUp(): void
    {
        $pdo=new SourceCleanupPdo();
        $this->cleanups=new SourceCleanupStoreFake($pdo);
        $this->storage=new SourceCleanupStorage($this->cleanups);
        $this->sources=new class {
            public ?ProjectSource $source=null;
            public bool $failPublication=false;
            public function isReadyForProject(int $id): bool { return false; }
            public function findForProject(int $id): ?ProjectSource { return $this->source; }
            public function findDirectUrlForProject(int $id): array { return ['sourceId'=>10,'url'=>'https://example.invalid/test.mp4']; }
            public function markStored(int $id,StoredObject $object,string $mime,string $extension): void {
                if ($this->failPublication) throw new \PDOException('source persistence failed');
                $this->source=new ProjectSource($id,4,'local',$object->objectKey(),$mime);
            }
            public function markReady(int $id,MediaMetadata $metadata): void {}
            public function markFailedForProject(int $id): void {}
        };
        $projects=new class { public function updateProcessingState(int $id,string $state,int $progress,?string $code=null,?string $message=null): void {} };
        $this->downloader=new class {
            public int $calls=0;
            public function downloadStoredUrl(string $url,$storage,string $key,int $limit): StoredObject { $this->calls++; return $storage->putUploaded('',$key); }
        };
        $processor=new class implements MediaProcessor { public function inspect(ProjectSource $source): MediaMetadata { return new MediaMetadata(20,160,90,'h264','aac',true); } };
        $this->effects=new class($pdo) implements ProcessingEffectGuard {
            public int $calls=0;
            public int $denyOnCall=0;
            public function __construct(private SourceCleanupPdo $pdo) {}
            public function apply(ClaimedJob $job,callable $effect): bool {
                if (++$this->calls===$this->denyOnCall) return false;
                $this->pdo->beginTransaction();
                try { $effect(); $this->pdo->commit(); return true; }
                catch (\Throwable $error) { $this->pdo->rollBack(); throw $error; }
            }
        };
        $this->handler=new FetchAndProbeHandler($projects,$this->sources,$this->downloader,$this->storage,$processor,$this->effects,1024,null,null,$this->cleanups);
        $this->job=new ClaimedJob(9,'media','fetch_and_probe',4,[],'fixture-worker',str_repeat('a',64),1,3);
    }

    public function testReservesUsingTheExactClaimBeforeDownloadAndReleasesWithPublication(): void
    {
        $outcome=$this->handler->handle($this->job);
        self::assertSame('completed',$outcome->status());
        self::assertTrue($this->storage->reservedBeforeWrite);
        self::assertSame($this->job,$this->cleanups->job);
        self::assertSame(['reserve','lock','release'],$this->cleanups->events);
        self::assertSame([],$this->cleanups->keys);
        self::assertSame([$this->sources->source->objectKey()],array_keys($this->storage->files));
    }
    public function testLostLeaseDuringReservationNeverDownloads(): void
    {
        $this->cleanups->reserveAllowed=false;
        $outcome=$this->handler->handle($this->job);
        self::assertSame('deferred',$outcome->status());
        self::assertSame(0,$this->downloader->calls);
        self::assertSame([],$this->storage->files);
    }
    public function testDatabaseReservationFailureNeverDownloadsAndKeepsPersistenceClassification(): void
    {
        $this->cleanups->reserveError=new \PDOException('outbox unavailable');
        $outcome=$this->handler->handle($this->job);
        self::assertSame('retry',$outcome->status());
        self::assertSame('processing_persistence_failed',$outcome->code());
        self::assertSame(0,$this->downloader->calls);
    }
    public function testPartialDownloadFailureWithCleanupFailureRemainsRecoverable(): void
    {
        $this->storage->writeError=MediaValidationException::withCode('remote_timeout');
        $this->storage->deleteFails=true;
        $outcome=$this->handler->handle($this->job);
        self::assertSame('retry',$outcome->status());
        self::assertSame('network_timeout',$outcome->code());
        self::assertCount(1,$this->cleanups->keys);
        self::assertCount(1,$this->storage->deleted);
        self::assertNull($this->sources->source);
    }
    public function testLeaseLossAtPublicationCannotLoseFailedCleanupReservation(): void
    {
        $this->effects->denyOnCall=2;
        $this->storage->deleteFails=true;
        $outcome=$this->handler->handle($this->job);
        self::assertSame('deferred',$outcome->status());
        self::assertCount(1,$this->cleanups->keys);
        self::assertNull($this->sources->source);
    }
    public function testPublicationFailureKeepsOutboxAndOriginalFailureClassification(): void
    {
        $this->sources->failPublication=true;
        $this->storage->deleteFails=true;
        $outcome=$this->handler->handle($this->job);
        self::assertSame('retry',$outcome->status());
        self::assertSame('processing_persistence_failed',$outcome->code());
        self::assertCount(1,$this->cleanups->keys);
        self::assertCount(1,$this->storage->deleted);
    }
    public function testExpiredReservationRejectsPublicationAndSuccessfulCleanupRemovesOnlyNewObject(): void
    {
        $this->cleanups->expired=true;
        $outcome=$this->handler->handle($this->job);
        self::assertNotSame('completed',$outcome->status());
        self::assertNull($this->sources->source);
        self::assertSame([],$this->cleanups->keys);
        self::assertSame([],$this->storage->files);
    }
}
