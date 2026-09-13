<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Services\ProjectAnalysisResumeService;
use PHPUnit\Framework\TestCase;
use Tests\Support\AnalysisResumeDatabase;

final class ProjectAnalysisResumeServiceTest extends TestCase
{
    public function testResumeUsesStoredSourceDebitsOnceAndQueuesOneAnalysis(): void
    {
        $pdo = new AnalysisResumeDatabase();
        self::assertSame('ai_queued',$pdo->service()->resume(11,7));
        self::assertSame('ai_queued',$pdo->service()->resume(11,7));
        self::assertSame(63,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        foreach (['credit_transactions','credit_reservations','processing_jobs','ai_analyses'] as $table) {
            self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
        }
        $job = $pdo->query('SELECT type,payload_json FROM processing_jobs')->fetch();
        self::assertSame('analyze_video',$job['type']);
        self::assertSame(['analysis_id'=>1,'reservation_id'=>1,'source_id'=>21],json_decode($job['payload_json'],true));
        self::assertSame(121,(int)$pdo->query('SELECT processed_duration_seconds FROM projects')->fetchColumn());
        self::assertNull($pdo->query('SELECT error_code FROM projects')->fetchColumn());
    }

    public function testInsufficientBalanceThenTopUpResumesExistingAnalysis(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $pdo->exec('UPDATE users SET credits=0 WHERE id=7');
        self::assertSame('awaiting_credits',$pdo->service()->resume(11,7));
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn());
        $pdo->exec('UPDATE users SET credits=3 WHERE id=7');
        self::assertSame('ai_queued',$pdo->service()->resume(11,7));
        self::assertSame(0,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM ai_analyses')->fetchColumn());
    }

    public function testOwnershipStatusAndSourceAreCheckedBeforeLazyScheduler(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $service = new ProjectAnalysisResumeService($pdo,static function(){ self::fail('Ineligible project constructed scheduler.'); });
        self::assertNull($service->resume(11,8));
        self::assertNull($service->resume(99,7));
        foreach (['ready','fetching','ai_queued','analyzing','failed','suggestions_ready','completed'] as $status) {
            $pdo->prepare('UPDATE projects SET status=?')->execute([$status]);
            self::assertSame($status,$service->resume(11,7));
        }
        $pdo->exec("UPDATE projects SET status='awaiting_credits'; UPDATE project_sources SET status='stored'");
        self::assertSame('source_unavailable',$service->resume(11,7));
        self::assertSame(66,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn());
    }

    public function testResumeAfterModelChangePreservesExistingAnalysisIdentity(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $pdo->exec('UPDATE users SET credits=0 WHERE id=7');
        self::assertSame('awaiting_credits',$pdo->service('original-model')->resume(11,7));
        $before = $pdo->query('SELECT id,prompt_version,model FROM ai_analyses')->fetch();
        $pdo->exec('UPDATE users SET credits=3 WHERE id=7');
        self::assertSame('ai_queued',$pdo->service('new-configured-model')->resume(11,7));
        self::assertSame($before,$pdo->query('SELECT id,prompt_version,model FROM ai_analyses')->fetch());
        self::assertSame('original-model',$before['model']);
        self::assertSame(0,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
        self::assertSame('ai_queued',$pdo->service('third-configured-model')->resume(11,7));
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
    }

    public function testPlanQuotaStillBlocksWithoutDebit(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $pdo->exec('UPDATE plans SET monthly_minutes=2');
        self::assertSame('failed',$pdo->service()->resume(11,7));
        self::assertSame('monthly_minutes_exceeded',$pdo->query('SELECT error_code FROM projects')->fetchColumn());
        self::assertSame(66,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn());
    }

    public function testQueueFailureRollsBackDebitUsageAndState(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $pdo->exec("CREATE TRIGGER reject_queue BEFORE INSERT ON processing_jobs BEGIN SELECT RAISE(ABORT,'queue unavailable'); END");
        try { $pdo->service()->resume(11,7); self::fail('Expected queue failure.'); }
        catch (\PDOException $exception) { self::assertStringContainsString('queue unavailable',$exception->getMessage()); }
        self::assertSame(66,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame('awaiting_credits',$pdo->query('SELECT status FROM projects')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT processed_duration_seconds FROM projects')->fetchColumn());
        foreach (['credit_transactions','credit_reservations','processing_jobs','ai_analyses'] as $table) {
            self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
        }
    }

    public function testConcurrentRequestsSerializeAndDebitOnlyOnce(): void
    {
        $path = tempnam(dirname(__DIR__,2).'/.local-history','resume-test-');
        self::assertIsString($path);
        $pdo = new AnalysisResumeDatabase('sqlite:'.$path);
        $processes = [];
        $pipes = [];
        try {
            $pdo->beginTransaction();
            $pdo->exec('UPDATE users SET credits=credits WHERE id=7');
            for ($index=0; $index<2; $index++) {
                $processes[$index] = proc_open([PHP_BINARY,dirname(__DIR__).'/Fixtures/analysis-resume-request.php',$path],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes[$index]);
                self::assertIsResource($processes[$index]);
                $ready = fgets($pipes[$index][1]);
                self::assertSame("READY\n",$ready,$ready === false ? stream_get_contents($pipes[$index][2]) : '');
            }
            $pdo->commit();
            foreach ($processes as $index=>$process) {
                self::assertSame("ai_queued\n",stream_get_contents($pipes[$index][1]));
                self::assertSame('',stream_get_contents($pipes[$index][2]));
                foreach ($pipes[$index] as $pipe) fclose($pipe);
                self::assertSame(0,proc_close($process));
            }
            $processes = [];
            self::assertSame(63,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
            foreach (['credit_transactions','credit_reservations','processing_jobs','ai_analyses'] as $table) {
                self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($processes as $process) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            unset($pdo);
            unlink($path);
        }
    }
}
