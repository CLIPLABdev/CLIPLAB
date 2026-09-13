<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Services\CreditReservationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\AnalysisResumeDatabase;

final class ProjectAnalysisRecoveryTest extends TestCase
{
    public function testInvalidResponseCanBeExplicitlyRecoveredWithFreshValidationBudgetAndSingleDebit(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis('ai_response_invalid');
        $pdo->exec('UPDATE ai_analyses SET validation_attempts=2');
        $recovery = new \App\Services\AiAnalysisRecoveryService($pdo);
        self::assertSame($refund, $recovery->recoveryToken(11, 7));
        self::assertNull($recovery->recoveryToken(11, 8));
        self::assertSame('ai_queued', $pdo->service()->resume(11, 7, $refund));
        self::assertSame(0, (int) $pdo->query('SELECT validation_attempts FROM ai_analyses')->fetchColumn());
        self::assertSame('ai_queued', $pdo->service()->resume(11, 7, $refund));
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ai_analyses')->fetchColumn());
        self::assertNull($pdo->query('SELECT validated_response_json FROM ai_analyses')->fetchColumn());
    }

    /** @dataProvider transientCodes */
    public function testRecoversRefundedTransientFailureWithOneNewDebitAndOriginalIdentity(string $code): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis($code);
        $before = $pdo->query('SELECT id,prompt_version,model FROM ai_analyses')->fetch();
        $usage = $pdo->query('SELECT processed_duration_seconds,usage_recorded_at FROM projects')->fetch();
        $source = $pdo->query('SELECT * FROM project_sources')->fetch();
        self::assertSame('ai_queued',$pdo->service()->resume(11,7,$refund));
        self::assertSame('ai_queued',$pdo->service()->resume(11,7,$refund));
        self::assertSame($before,$pdo->query('SELECT id,prompt_version,model FROM ai_analyses')->fetch());
        self::assertSame($usage,$pdo->query('SELECT processed_duration_seconds,usage_recorded_at FROM projects')->fetch());
        self::assertSame($source,$pdo->query('SELECT * FROM project_sources')->fetch());
        self::assertSame([63,66,63],array_map('intval',$pdo->query('SELECT balance_after FROM credit_transactions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(63,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame('reserved',$pdo->query('SELECT status FROM credit_reservations')->fetchColumn());
        self::assertNull($pdo->query('SELECT refund_transaction_id FROM credit_reservations')->fetchColumn());
        $job = $pdo->query('SELECT id,status,attempts,max_attempts,lease_token_hash,worker_id,leased_until FROM processing_jobs')->fetch();
        foreach (['id','attempts','max_attempts'] as $field) $job[$field]=(int)$job[$field];
        self::assertSame(['id'=>1,'status'=>'queued','attempts'=>0,'max_attempts'=>6,'lease_token_hash'=>null,'worker_id'=>null,'leased_until'=>null],$job);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn());
        self::assertNull($pdo->query('SELECT gemini_file_name FROM ai_analyses')->fetchColumn());
        self::assertSame('queued',$pdo->query('SELECT status FROM ai_analyses')->fetchColumn());
    }

    public function transientCodes(): iterable
    {
        foreach (['ai_unavailable','ai_timeout','ai_rate_limited'] as $code) yield $code=>[$code];
    }

    public function testRecoveryAttemptBudgetIsCappedAtEight(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis();
        self::assertSame('ai_queued',(new \App\Services\AiAnalysisRecoveryService($pdo,1,99))->recover(11,7,$refund));
        self::assertSame(8,(int)$pdo->query('SELECT max_attempts FROM processing_jobs')->fetchColumn());
    }

    public function testStaleRecoveryTokenCannotDebitAfterANewRefund(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $firstRefund = $pdo->failedAnalysis();
        self::assertSame('ai_queued',$pdo->service()->resume(11,7,$firstRefund));
        $secondRefund = $pdo->failedAnalysis();
        self::assertNotSame($firstRefund,$secondRefund);
        self::assertSame('recovery_stale',$pdo->service()->resume(11,7,$firstRefund));
        self::assertSame(4,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
        self::assertSame(66,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame('ai_queued',$pdo->service()->resume(11,7,$secondRefund));
        $credits = new CreditReservationService($pdo,new CreditReservationRepository($pdo),new CreditTransactionRepository($pdo),1);
        self::assertSame('consumed',$credits->consume(1)->status());
        self::assertSame('consumed',$credits->consume(1)->status());
        self::assertSame('consumed',$credits->refund(1,'ai_unavailable')->status());
        self::assertSame([63,66,63,66,63],array_map('intval',$pdo->query('SELECT balance_after FROM credit_transactions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testInsufficientBalanceLeavesFailureAndOriginalCheckpointUntouched(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis();
        $pdo->exec("INSERT INTO credit_transactions(user_id,type,amount,balance_after,reference_type) VALUES(7,'debit',66,0,'other'); UPDATE users SET credits=0 WHERE id=7");
        self::assertSame('insufficient_credits',$pdo->service()->resume(11,7,$refund));
        self::assertSame('failed',$pdo->query('SELECT status FROM projects')->fetchColumn());
        self::assertSame('files/old',$pdo->query('SELECT gemini_file_name FROM ai_analyses')->fetchColumn());
        self::assertSame('refunded',$pdo->query('SELECT status FROM credit_reservations')->fetchColumn());
        self::assertSame(3,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
    }

    /** @dataProvider unsafeStates */
    public function testRejectsUnsafeRecoveryWithoutChangingLedgerOrSource(string $sql): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis();
        $pdo->exec($sql);
        $source = $pdo->query('SELECT * FROM project_sources')->fetch();
        self::assertNotSame('ai_queued',$pdo->service()->resume(11,7,$refund));
        self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
        self::assertSame($source,$pdo->query('SELECT * FROM project_sources')->fetch());
    }

    public function unsafeStates(): iterable
    {
        yield 'permanent error'=>["UPDATE projects SET error_code='ai_provider_rejected'"];
        yield 'job active'=>["UPDATE processing_jobs SET status='running',lease_token_hash='old',worker_id='old',leased_until='2099-01-01'"];
        yield 'stale lease fields'=>["UPDATE processing_jobs SET lease_token_hash='old'"];
        yield 'output exists'=>["UPDATE ai_analyses SET validated_response_json='{}'"];
        yield 'summary exists'=>["UPDATE ai_analyses SET video_summary='old output'"];
        yield 'clip exists'=>['INSERT INTO clips VALUES(1,11,1)'];
        yield 'source not ready'=>["UPDATE project_sources SET status='failed'"];
        yield 'reservation consumed'=>["UPDATE credit_reservations SET status='consumed'"];
        yield 'payload mismatch'=>["UPDATE processing_jobs SET payload_json='{\"analysis_id\":99,\"source_id\":21,\"reservation_id\":1}'"];
        yield 'changed duration'=>['UPDATE project_sources SET duration_seconds=120'];
        yield 'plan inactive'=>['UPDATE plans SET is_active=0'];
        yield 'user inactive'=>["UPDATE users SET status='suspended'"];
    }

    public function testFailedRequeueRollsBackNewDebitAndAllRearmedStates(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis();
        $pdo->exec("CREATE TRIGGER reject_recovery BEFORE UPDATE ON processing_jobs WHEN NEW.status='queued' BEGIN SELECT RAISE(ABORT,'queue unavailable'); END");
        try { $pdo->service()->resume(11,7,$refund); self::fail('Expected rollback.'); }
        catch (\PDOException $exception) { self::assertStringContainsString('queue unavailable',$exception->getMessage()); }
        self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
        self::assertSame(66,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        self::assertSame('refunded',$pdo->query('SELECT status FROM credit_reservations')->fetchColumn());
        self::assertSame('failed',$pdo->query('SELECT status FROM projects')->fetchColumn());
        self::assertSame('files/old',$pdo->query('SELECT gemini_file_name FROM ai_analyses')->fetchColumn());
    }

    public function testReadOnlyRecoveryTokenIsBoundToOwnerAndLatestRefund(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis();
        $recovery = new \App\Services\AiAnalysisRecoveryService($pdo);
        self::assertSame($refund,$recovery->recoveryToken(11,7));
        self::assertNull($recovery->recoveryToken(11,8));
        self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
        self::assertSame('failed',$pdo->query('SELECT status FROM processing_jobs')->fetchColumn());
        $pdo->service()->resume(11,7,$refund);
        self::assertNull($recovery->recoveryToken(11,7));
    }

    public function testOldWorkerLeaseCannotMutateRearmedReservation(): void
    {
        $pdo = new AnalysisResumeDatabase();
        $refund = $pdo->failedAnalysis();
        $old = new \App\Queue\ClaimedJob(1,'media','analyze_video',11,
            ['analysis_id'=>1,'source_id'=>21,'reservation_id'=>1],'worker-old','token-old',3,3);
        $pdo->service()->resume(11,7,$refund);
        $guard = new \App\Queue\LeaseProcessingEffectGuard($pdo);
        self::assertFalse($guard->apply($old,static function(){ self::fail('Old lease reached financial effect.'); }));
        $pdo->prepare("UPDATE processing_jobs SET status='running',worker_id='worker-new',lease_token_hash=?,leased_until='2099-01-01'")->execute([hash('sha256','token-new')]);
        self::assertFalse($guard->apply($old,static function(){ self::fail('Old lease reached new attempt.'); }));
        $current = new \App\Queue\ClaimedJob(1,'media','analyze_video',11,
            ['analysis_id'=>1,'source_id'=>21,'reservation_id'=>1],'worker-new','token-new',1,6);
        self::assertTrue($guard->apply($current,static function(){}));
        self::assertSame(3,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
    }

    public function testConcurrentRecoveryRequestsAppendOnlyOneDebit(): void
    {
        $path = tempnam(dirname(__DIR__,2).'/.local-history','resume-test-');
        self::assertIsString($path);
        $pdo = new AnalysisResumeDatabase('sqlite:'.$path);
        $refund = $pdo->failedAnalysis();
        $processes = []; $pipes = [];
        try {
            $pdo->beginTransaction();
            $pdo->exec('UPDATE users SET credits=credits WHERE id=7');
            for ($index=0; $index<2; $index++) {
                $processes[$index] = proc_open([PHP_BINARY,dirname(__DIR__).'/Fixtures/analysis-resume-request.php',$path,(string)$refund],
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
            self::assertSame(3,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
            foreach (['credit_reservations','processing_jobs','ai_analyses'] as $table) {
                self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($processes as $process) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            unset($pdo); unlink($path);
        }
    }
}
