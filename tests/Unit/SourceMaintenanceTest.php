<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CompositeWorkerMaintenance;
use App\Services\SourceMaintenance;
use App\Queue\WorkerMaintenance;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceCleanupStoreFake;
use Tests\Support\SourceCleanupStorage;

require_once dirname(__DIR__).'/Support/SourceCleanupFakes.php';

final class SourceMaintenanceTest extends TestCase
{
    public function testMaintenanceProcessesOnlyTwentyFiveReservationsPerRun(): void
    {
        $store=new SourceCleanupStoreFake();
        $storage=new SourceCleanupStorage($store);
        for ($index=1;$index<=26;$index++) {
            $key='imports/4/'.str_pad(dechex($index),32,'0',STR_PAD_LEFT).'.mp4';
            $store->reserve($key);
            $storage->putUploaded('',$key);
        }
        $maintenance=new SourceMaintenance($store,$storage);
        $maintenance->run();
        self::assertCount(25,$storage->deleted);
        self::assertCount(1,$store->keys);
        self::assertCount(1,$storage->files);
        $maintenance->run();
        self::assertSame([],$store->keys);
        self::assertSame([],$storage->files);
    }
    public function testFailedDeletePreservesReservationForTheNextRun(): void
    {
        $store=new SourceCleanupStoreFake();
        $storage=new SourceCleanupStorage($store);
        $key='imports/4/'.str_repeat('a',32).'.mp4';
        $store->reserve($key);
        $storage->putUploaded('',$key);
        $storage->deleteFails=true;
        (new SourceMaintenance($store,$storage))->run();
        self::assertCount(1,$store->keys);
        self::assertCount(1,$storage->files);
        $storage->deleteFails=false;
        (new SourceMaintenance($store,$storage))->run();
        self::assertSame([],$store->keys);
        self::assertSame([],$storage->files);
    }
    public function testMaintenanceRemovesAClosedCrashStagingFileWithoutAFinalObject(): void
    {
        $root=sys_get_temp_dir().'/source-maintenance-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root,0700,true));
        try {
            $storage=new LocalPrivateStorage($root,1024,static fn (string $path): bool=>true);
            $key='imports/4/'.str_repeat('a',32).'.mp4';
            $staging=$storage->createStaging($key);
            $staging->write('partial download',1024);
            $stagingPath=$staging->path();
            $staging->close(); // Simulate a terminated process whose OS handle was released.
            self::assertFileExists($stagingPath);
            self::assertFileDoesNotExist($storage->absolutePath($key));
            $store=new SourceCleanupStoreFake();
            $store->reserve($key);

            (new SourceMaintenance($store,$storage))->run();

            self::assertFileDoesNotExist($stagingPath);
            self::assertSame([],$store->keys);
        } finally {
            $iterator=is_dir($root) ? new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) : null;
            if ($iterator!==null) foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            @rmdir($root);
        }
    }
    public function testCompositeRunsEveryMaintenanceAndPropagatesTheLastFailure(): void
    {
        $events=new \ArrayObject();
        $first=new \RuntimeException('first maintenance failure');
        $last=new \RuntimeException('last maintenance failure');
        $make=static fn (string $name,?\Throwable $error)=>new class($events,$name,$error) implements WorkerMaintenance {
            public function __construct(private \ArrayObject $events,private string $name,private ?\Throwable $error) {}
            public function run(): void { $this->events[]=$this->name; if ($this->error) throw $this->error; }
        };
        $composite=new CompositeWorkerMaintenance([$make('render',$first),$make('source',$last),$make('other',null)]);
        try { $composite->run(); self::fail('Last error should reach the worker observer.'); }
        catch (\RuntimeException $error) { self::assertSame($last,$error); }
        self::assertSame(['render','source','other'],$events->getArrayCopy());
        (new CompositeWorkerMaintenance([$make('successful',null)]))->run();
        self::assertSame('successful',$events[3]);
    }
    public function testCompositeRejectsInvalidItemsBeforeRunningAnyMaintenance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CompositeWorkerMaintenance([new \stdClass()]);
    }
}
