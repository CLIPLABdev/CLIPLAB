<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Contracts\JobDispatcher;
use App\Media\PreciseSourceDuration;
use App\Media\Reframe\ReframePlanValidator;
use App\Repositories\{ClipRepository,ProjectRepository,ClipRenderProfileRepository,ProjectSourceRepository};
use App\Services\{AutoRenderScheduler,ClipRenderRequestService,SourceDurationPreflight};
use PHPUnit\Framework\TestCase;
use Tests\Support\{EofTestDatabase,PreciseMediaProcessor};

final class AutoRenderPrecisionTest extends TestCase
{
    private function fixture():array
    {
        $pdo=new EofTestDatabase();
        $pdo->exec("UPDATE clips SET status='suggested',render_revision=0,render_start_time=NULL,render_end_time=NULL");
        $probe=new PreciseMediaProcessor($pdo,45011);
        $reader=new SourceDurationPreflight($pdo,new ProjectSourceRepository($pdo),$probe);
        $jobs=new class implements JobDispatcher {
            public int $calls=0;
            public function dispatch(string $type,int $projectId,array $payload,string $idempotencyKey):int { return ++$this->calls; }
        };
        $requests=new ClipRenderRequestService($pdo,new ClipRepository($pdo),new ProjectRepository($pdo),
            new ClipRenderProfileRepository($pdo),new ReframePlanValidator(),$jobs,180,$reader);
        $scheduler=new AutoRenderScheduler($pdo,
            static fn(int $clip,int $owner,string $start,string $end,?PreciseSourceDuration $snapshot=null)
                => $requests->request($clip,$owner,$start,$end,null,$snapshot),3,$reader);
        return [$pdo,$probe,$jobs,$scheduler];
    }
    public function testAutoCapsOnlyRenderBoundsToExactEofAndReplaysWithoutExtraProbe():void
    {
        [$pdo,$probe,$jobs,$scheduler]=$this->fixture();
        self::assertTrue(method_exists($scheduler,'prepare'),'Automatic preflight is missing.');
        $snapshot=$scheduler->prepare(31,11,7);
        self::assertSame(1,$probe->calls);
        $pdo->beginTransaction();
        self::assertSame(1,$scheduler->schedule(31,11,7,$snapshot));
        $pdo->commit();
        $clip=$pdo->query('SELECT * FROM clips WHERE id=41')->fetch();
        self::assertSame(45.011,(float)$clip['render_end_time']);
        self::assertSame(46.0,(float)$clip['end_time']);
        self::assertSame(46.0,(float)$clip['duration_seconds']);
        self::assertSame(46,(int)$pdo->query('SELECT duration_seconds FROM project_sources')->fetchColumn());
        self::assertSame(64,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        $probe->fail=true;
        self::assertNull($scheduler->prepare(31,11,7));
        $pdo->beginTransaction();
        self::assertSame(0,$scheduler->schedule(31,11,7));
        $pdo->commit();
        self::assertSame(1,$jobs->calls);
        self::assertSame(1,$probe->calls);
    }
    public function testForeignOwnerOptOutAndMissingPrecisionCannotAutoQueue():void
    {
        [$pdo,$probe,$jobs,$scheduler]=$this->fixture();
        self::assertTrue(method_exists($scheduler,'prepare'),'Automatic preflight is missing.');
        self::assertNull($scheduler->prepare(31,11,8));
        $pdo->exec('UPDATE projects SET auto_render_requested=0');
        self::assertNull($scheduler->prepare(31,11,7));
        self::assertSame(0,$probe->calls);
        $pdo->exec('UPDATE projects SET auto_render_requested=1');
        $pdo->beginTransaction();
        try {
            $scheduler->schedule(31,11,7);
            self::fail('Scheduling accepted no precise snapshot.');
        } catch (\RuntimeException) {
            self::assertSame(0,$jobs->calls);
        } finally { $pdo->rollBack(); }
    }
}
