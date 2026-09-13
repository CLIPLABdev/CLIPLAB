<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JobDispatcher;
use App\Media\DirectUrlValidator;
use App\Media\UploadValidator;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\ProjectIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceCleanupPdo;
use Tests\Support\SourceCleanupStorage;
use Tests\Support\SourceCleanupStoreFake;

require_once dirname(__DIR__).'/Support/SourceCleanupFakes.php';

final class ProjectIntakeSourceCleanupTest extends TestCase
{
    private string $temporary;
    private SourceCleanupPdo $pdo;
    private SourceCleanupStoreFake $cleanups;
    private SourceCleanupStorage $storage;
    protected function setUp(): void
    {
        $this->temporary=tempnam(sys_get_temp_dir(),'source-cleanup-');
        file_put_contents($this->temporary,"\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41");
        $this->pdo=new SourceCleanupPdo();
        $this->cleanups=new SourceCleanupStoreFake($this->pdo);
        $this->storage=new SourceCleanupStorage($this->cleanups);
    }
    protected function tearDown(): void { if (isset($this->temporary)) @unlink($this->temporary); }

    public function testReservesBeforeUploadAndReleasesInsideSuccessfulPublication(): void
    {
        $receipt=$this->upload();
        self::assertTrue($receipt->created());
        self::assertTrue($this->storage->reservedBeforeWrite);
        self::assertSame(['reserve','lock','release'],$this->cleanups->events);
        self::assertSame([], $this->cleanups->keys);
        self::assertSame(array_keys($this->storage->files),$this->pdo->keys);
        self::assertFalse($this->pdo->inTransaction());
    }
    public function testFailedReservationPreventsAnyStorageWrite(): void
    {
        $failure=new \RuntimeException('reservation unavailable');
        $this->cleanups->reserveError=$failure;
        try { $this->upload(); self::fail('Reservation failure must stop upload.'); }
        catch (\RuntimeException $caught) { self::assertSame($failure,$caught); }
        self::assertSame(0,$this->storage->writes);
        self::assertSame([],$this->pdo->events);
    }
    public function testPartialStorageFailureKeepsDurableReservationWhenDeleteAlsoFails(): void
    {
        $failure=new \RuntimeException('partial upload failed');
        $this->storage->writeError=$failure;
        $this->storage->deleteFails=true;
        try { $this->upload(); self::fail('Upload failure expected.'); }
        catch (\RuntimeException $caught) { self::assertSame($failure,$caught); }
        self::assertTrue($this->storage->reservedBeforeWrite);
        self::assertCount(1,$this->cleanups->keys);
        self::assertCount(1,$this->storage->deleted);
        self::assertSame(array_keys($this->storage->files),array_keys($this->cleanups->keys));
    }
    public function testDatabaseFailureAndCleanupFailurePreserveOriginalExceptionAndReservation(): void
    {
        $failure=new \RuntimeException('job persistence failed');
        $this->storage->deleteFails=true;
        try { $this->upload($failure); self::fail('Persistence failure expected.'); }
        catch (\RuntimeException $caught) { self::assertSame($failure,$caught); }
        self::assertSame([],$this->pdo->keys);
        self::assertCount(1,$this->cleanups->keys);
        self::assertCount(1,$this->storage->deleted);
    }
    public function testReplayReturnsExistingReceiptEvenIfDuplicateObjectNeedsLaterCleanup(): void
    {
        $this->pdo->replay=true;
        $this->pdo->keys=['users/7/uploads/original.mp4'];
        $this->storage->deleteFails=true;
        $receipt=$this->upload();
        self::assertFalse($receipt->created());
        self::assertSame(4,$receipt->projectId());
        self::assertSame(['users/7/uploads/original.mp4'],$this->pdo->keys);
        self::assertCount(1,$this->cleanups->keys);
        self::assertNotContains('users/7/uploads/original.mp4',$this->storage->deleted);
    }
    public function testExpiredReservationNeverPublishesSourceAndSuccessfulDeleteForgetsIt(): void
    {
        $this->cleanups->expired=true;
        try { $this->upload(); self::fail('An expired reservation cannot publish.'); }
        catch (\RuntimeException $caught) { self::assertSame('Source reservation expired.',$caught->getMessage()); }
        self::assertSame([],$this->pdo->keys);
        self::assertSame([],$this->storage->files);
        self::assertSame([],$this->cleanups->keys);
    }
    public function testRollbackFailureCannotMaskTheOriginalFailure(): void
    {
        $failure=new \RuntimeException('first database failure');
        $this->pdo->rollbackFails=true;
        try { $this->upload($failure); self::fail('Database failure expected.'); }
        catch (\RuntimeException $caught) { self::assertSame($failure,$caught); }
        self::assertCount(1,$this->cleanups->keys);
    }

    private function upload(?\Throwable $dispatchFailure=null): \App\Media\ProjectReceipt
    {
        $jobs=new class($dispatchFailure) implements JobDispatcher {
            public function __construct(private ?\Throwable $failure) {}
            public function dispatch(string $type,int $projectId,array $payload,string $idempotencyKey): int { if ($this->failure) throw $this->failure; return 9; }
        };
        $service=new ProjectIntakeService($this->pdo,new ProjectRepository($this->pdo),new ProjectSourceRepository($this->pdo),$this->storage,$jobs,
            new UploadValidator(1024,static fn (): string=>'video/mp4'),new DirectUrlValidator(static fn (): array=>['1.1.1.1']),null,$this->cleanups);
        return $service->fromUpload(7,['name'=>'Source fixture','idempotency_key'=>'fixture-key'],
            ['name'=>'source.mp4','tmp_name'=>$this->temporary,'size'=>filesize($this->temporary),'error'=>UPLOAD_ERR_OK]);
    }
}
