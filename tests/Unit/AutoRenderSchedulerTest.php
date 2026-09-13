<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AutoRenderScheduler;
use PDO;
use PHPUnit\Framework\TestCase;

final class AutoRenderSchedulerTest extends TestCase
{
    private function database(): PDO
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY,user_id INTEGER,auto_render_requested INTEGER)');
        $pdo->exec('CREATE TABLE ai_analyses (id INTEGER PRIMARY KEY,project_id INTEGER)');
        $pdo->exec('CREATE TABLE clips (id INTEGER PRIMARY KEY,project_id INTEGER,ai_analysis_id INTEGER,suggestion_index INTEGER,status TEXT,render_revision INTEGER,viral_score INTEGER,start_time TEXT,end_time TEXT)');
        $pdo->exec('INSERT INTO projects VALUES (1,17,1),(2,18,0)');
        $pdo->exec('INSERT INTO ai_analyses VALUES (1,1),(2,2)');
        $pdo->exec('CREATE TABLE project_sources(id INTEGER PRIMARY KEY,project_id INTEGER,status TEXT,storage_disk TEXT,object_key TEXT,mime_type TEXT,sha256 TEXT,size_bytes INTEGER,duration_seconds INTEGER)');
        $pdo->prepare("INSERT INTO project_sources VALUES(1,1,'ready','local','source.mp4','video/mp4',?,1024,30)")->execute([str_repeat('a',64)]);
        $pdo->exec("INSERT INTO clips VALUES (1,1,1,0,'suggested',0,70,'0.000','5.000'),(2,1,1,1,'suggested',0,99,'5.000','10.000'),(3,1,1,2,'suggested',0,90,'10.000','15.000'),(4,1,1,3,'suggested',0,80,'15.000','20.000'),(5,1,1,NULL,'suggested',0,100,'20.000','25.000'),(6,2,2,0,'suggested',0,99,'0','5')");
        return $pdo;
    }

    public function testSchedulesOnlyThreeTopOriginalSuggestionsOnceInsideTransaction(): void
    {
        $pdo=$this->database();
        $calls=[];
        $render=static function(int $clip,int $owner,string $start,string $end) use ($pdo,&$calls): void {
            self::assertTrue($pdo->inTransaction());
            $calls[]=[$clip,$owner,$start,$end];
            $pdo->prepare("UPDATE clips SET render_revision=1,status='queued' WHERE id=?")->execute([$clip]);
        };
        $probe=new \Tests\Support\PreciseMediaProcessor($pdo,30000);
        $reader=new \App\Services\SourceDurationPreflight($pdo,new \App\Repositories\ProjectSourceRepository($pdo),$probe);
        $scheduler=new AutoRenderScheduler($pdo,$render,3,$reader);
        $prepared=$scheduler->prepare(1,1,17);
        $pdo->beginTransaction();
        self::assertSame(3,$scheduler->schedule(1,1,17,$prepared));
        self::assertSame([2,3,4],array_column($calls,0));
        self::assertSame(0,$scheduler->schedule(1,1,17));
        self::assertCount(3,$calls);
        self::assertSame(1,$probe->calls);
        $pdo->commit();
    }

    public function testOptOutForeignAndObsoleteAnalysisDoNotSchedule(): void
    {
        $pdo=$this->database();
        $calls=0;
        $scheduler=new AutoRenderScheduler($pdo,static function() use (&$calls): void { ++$calls; });
        $pdo->beginTransaction();
        self::assertSame(0,$scheduler->schedule(2,2,18));
        self::assertSame(0,$scheduler->schedule(1,1,18));
        $pdo->exec('INSERT INTO ai_analyses VALUES (3,1)');
        self::assertSame(0,$scheduler->schedule(1,1,17));
        self::assertSame(0,$calls);
        $pdo->rollBack();
    }

    public function testRequiresAnAtomicTransaction(): void
    {
        $this->expectException(\LogicException::class);
        (new AutoRenderScheduler($this->database(),static function(): void {}))->schedule(1,1,17);
    }
}
