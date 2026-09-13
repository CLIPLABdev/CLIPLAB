<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\MediaProcessor;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Repositories\ProjectSourceRepository;
use App\Services\SourceDurationPreflight;
use PDO;
use PHPUnit\Framework\TestCase;

final class SourceDurationPreflightTest extends TestCase
{
    private function fixture(): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,user_id INTEGER)');
        $pdo->exec('CREATE TABLE project_sources (id INTEGER PRIMARY KEY,project_id INTEGER,storage_disk TEXT,object_key TEXT,mime_type TEXT,sha256 TEXT,size_bytes INTEGER,status TEXT,duration_seconds INTEGER)');
        $pdo->exec('INSERT INTO projects VALUES (11,7),(12,8)');
        $pdo->prepare('INSERT INTO project_sources VALUES (21,11,?,?,?,?,?,?,?)')
            ->execute(['local','imports/11/source.mp4','video/mp4',str_repeat('a',64),1024,'ready',46]);
        $processor = new class($pdo) implements MediaProcessor {
            public int $calls = 0;
            public ?MediaMetadata $result = null;
            public function __construct(private PDO $pdo) {}
            public function inspect(ProjectSource $source): MediaMetadata {
                if ($this->pdo->inTransaction()) throw new \LogicException('Probe inside transaction.');
                ++$this->calls;
                if ($this->result === null) throw new \RuntimeException('Private probe details.');
                return $this->result;
            }
        };
        $processor->result = new MediaMetadata(46,1920,1080,'h264','aac',true,45011);
        return [$pdo,$processor,new ProjectSourceRepository($pdo)];
    }

    public function testMeasuresOutsideTransactionThenChecksSameIdentityWithoutAnotherProbe(): void
    {
        self::assertTrue(class_exists(SourceDurationPreflight::class), 'Owner-scoped precise preflight is missing.');
        [$pdo,$processor,$repository] = $this->fixture();
        $reader = new SourceDurationPreflight($pdo,$repository,$processor);
        $snapshot = $reader->prepare(11,7);
        self::assertNotNull($snapshot);
        self::assertSame(45011,$snapshot->milliseconds());
        self::assertSame('45.011',$snapshot->endDecimal());
        self::assertSame(1,$processor->calls);
        self::assertSame(46,(int)$pdo->query('SELECT duration_seconds FROM project_sources')->fetchColumn());
        $pdo->beginTransaction();
        $reader->assertCurrent($snapshot,11,7);
        $reader->assertCurrent($snapshot,11,7);
        self::assertSame(1,$processor->calls);
        $pdo->rollBack();
    }

    public function testForeignOrMissingSourceNeverTriggersProbe(): void
    {
        self::assertTrue(class_exists(SourceDurationPreflight::class), 'Owner-scoped precise preflight is missing.');
        [$pdo,$processor,$repository] = $this->fixture();
        $reader = new SourceDurationPreflight($pdo,$repository,$processor);
        self::assertNull($reader->prepare(11,8));
        self::assertNull($reader->prepare(12,8));
        self::assertSame(0,$processor->calls);
    }

    public function testCannotProbeInsideTransaction(): void
    {
        self::assertTrue(class_exists(SourceDurationPreflight::class), 'Owner-scoped precise preflight is missing.');
        [$pdo,$processor,$repository] = $this->fixture();
        $reader = new SourceDurationPreflight($pdo,$repository,$processor);
        $pdo->beginTransaction();
        try {
            $reader->prepare(11,7);
            self::fail('Preflight accepted an open transaction.');
        } catch (\LogicException) {
            self::assertSame(0,$processor->calls);
        } finally { $pdo->rollBack(); }
    }

    public function testChangingAnySourceIdentityFieldInvalidatesSnapshotUnderLock(): void
    {
        self::assertTrue(class_exists(SourceDurationPreflight::class), 'Owner-scoped precise preflight is missing.');
        foreach (['id'=>22,'object_key'=>'imports/11/changed.mp4','sha256'=>str_repeat('b',64),
            'size_bytes'=>2048,'status'=>'stored','duration_seconds'=>47,'storage_disk'=>'other','mime_type'=>'video/webm'] as $field=>$value) {
            [$pdo,$processor,$repository] = $this->fixture();
            $reader = new SourceDurationPreflight($pdo,$repository,$processor);
            $snapshot = $reader->prepare(11,7);
            $pdo->prepare('UPDATE project_sources SET '.$field.'=?')->execute([$value]);
            $pdo->beginTransaction();
            try {
                $reader->assertCurrent($snapshot,11,7);
                self::fail('Changed source was accepted: '.$field);
            } catch (\RuntimeException) {
                self::assertSame(1,$processor->calls);
            } finally { $pdo->rollBack(); }
        }
    }

    public function testOwnerChangeOrSnapshotForAnotherBindingCannotBeReused(): void
    {
        self::assertTrue(class_exists(SourceDurationPreflight::class), 'Owner-scoped precise preflight is missing.');
        [$pdo,$processor,$repository] = $this->fixture();
        $reader = new SourceDurationPreflight($pdo,$repository,$processor);
        $snapshot = $reader->prepare(11,7);
        $pdo->exec('UPDATE projects SET user_id=8 WHERE id=11');
        $pdo->beginTransaction();
        try {
            $reader->assertCurrent($snapshot,11,8);
            self::fail('Snapshot changed owners.');
        } catch (\RuntimeException) {
            self::assertSame(1,$processor->calls);
        } finally { $pdo->rollBack(); }
    }

    public function testMissingPrecisionAndProbeFailureNeverFallBackToCeiling(): void
    {
        self::assertTrue(class_exists(SourceDurationPreflight::class), 'Owner-scoped precise preflight is missing.');
        foreach ([null,new MediaMetadata(46,1920,1080,'h264','aac',true)] as $result) {
            [$pdo,$processor,$repository] = $this->fixture();
            $processor->result=$result;
            try {
                (new SourceDurationPreflight($pdo,$repository,$processor))->prepare(11,7);
                self::fail('A missing precise measurement was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('Private probe details',$error->getMessage());
                self::assertFalse($pdo->inTransaction());
                self::assertSame(46,(int)$pdo->query('SELECT duration_seconds FROM project_sources')->fetchColumn());
            }
        }
    }
}
