<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Queue\ClaimedJob;
use App\Queue\JobHandler;
use App\Queue\JobOutcome;
use App\Services\QueueWorker;
use App\Repositories\ProcessingJobRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProcessingJobHardeningTest extends TestCase
{
    private PDO $pdo;
    private ProcessingJobRepository $repository;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO((string) $dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/seeds/plans.sql'));
        $this->pdo->exec('DELETE FROM processing_jobs');
        $this->repository = new ProcessingJobRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->exec('DELETE FROM processing_jobs');
        $this->pdo->exec("DELETE FROM users WHERE name = 'Queue hardening test'");
        $this->pdo->exec("DELETE FROM plans WHERE slug LIKE 'queue-hardening-%'");
    }

    public function testExpiredExhaustedLeaseDoesNotBlockAnEligibleClaim(): void
    {
        $expiredId = $this->insertJob(1);
        $expired = $this->repository->claimNext('media', 'worker-old', 60);
        self::assertNotNull($expired);
        $this->pdo->prepare('UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([$expiredId]);
        $eligibleId = $this->insertJob(3);

        $claimed = $this->repository->claimNext('media', 'worker-new', 120);

        self::assertSame($eligibleId, $claimed?->id());
        self::assertSame('running', $this->status($expiredId));
        self::assertSame(1, (int) $this->pdo->query("SELECT attempts FROM processing_jobs WHERE id = {$expiredId}")->fetchColumn());
    }

    public function testExpiredExhaustedNonterminalProjectIsDeferredForReconciliation(): void
    {
        $jobId = $this->insertJob(1);
        $claimed = $this->repository->claimNext('media', 'worker-old', 60);
        self::assertNotNull($claimed);
        $this->pdo->prepare("UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?")
            ->execute([$jobId]);

        self::assertFalse($this->repository->failOneExpiredExhausted('media'));
        $row = $this->pdo->query("SELECT status, last_error_code FROM processing_jobs WHERE id = {$jobId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('retry', $row['status']);
        self::assertSame('processing_persistence_failed', $row['last_error_code']);
    }

    public function testFailOneExpiredExhaustedDefersOnlyOneNonterminalProject(): void
    {
        for ($index = 0; $index < 4; ++$index) {
            $this->insertExpiredExhaustedJob();
        }

        self::assertTrue(method_exists($this->repository, 'failOneExpiredExhausted'));
        self::assertFalse($this->repository->failOneExpiredExhausted('media'));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM processing_jobs WHERE status = 'retry'")->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM processing_jobs WHERE status = 'running'")->fetchColumn());
    }
    public function testWorkerCleansOneExpiredLeaseWithoutStarvingAnEligibleJob(): void
    {
        for ($index = 0; $index < 4; ++$index) {
            $this->insertExpiredExhaustedJob();
        }
        $eligibleId = $this->insertJob(3);

        $worker = new QueueWorker(
            $this->repository,
            ['probe_source' => new class implements JobHandler {
                public function handle(ClaimedJob $job): JobOutcome
                {
                    return JobOutcome::completed();
                }
            }],
            'worker-test',
            120,
            static fn (): int => 0
        );

        $report = $worker->run('media', 1, 50);

        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->completed);
        self::assertSame(0, $report->failed);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM processing_jobs WHERE status = 'retry'")->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM processing_jobs WHERE status = 'running'")->fetchColumn());
        self::assertSame('completed', $this->status($eligibleId));
    }

    public function testRetryReportsTerminalFailureAtMaximumAttempts(): void
    {
        $jobId = $this->insertJob(1);
        $job = $this->repository->claimNext('media', 'worker-a', 60);
        self::assertNotNull($job);

        $result = $this->repository->retry(
            $job,
            'processor_unavailable',
            'Processador temporariamente indisponível.',
            new DateTimeImmutable('+15 seconds', new DateTimeZone('UTC'))
        );

        self::assertTrue($result);
        self::assertSame('failed', $this->status($jobId));
    }

    public function testSuccessfulCompletionClearsPreviousPublicError(): void
    {
        $jobId = $this->insertJob(3);
        $job = $this->repository->claimNext('media', 'worker-a', 60);
        self::assertNotNull($job);
        $this->pdo->prepare("UPDATE processing_jobs SET last_error_code = 'processor_unavailable', last_error_message = 'Processador temporariamente indisponível.' WHERE id = ?")
            ->execute([$jobId]);

        self::assertTrue($this->repository->complete($job));
        $row = $this->pdo->query("SELECT last_error_code, last_error_message FROM processing_jobs WHERE id = {$jobId}")->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['last_error_code']);
        self::assertNull($row['last_error_message']);
    }

    public function testLeaseDeadlineIsCalculatedFromTheDatabaseUtcClock(): void
    {
        $this->insertJob(3);

        $job = $this->repository->claimNext('media', 'worker-a', 120);

        self::assertNotNull($job);
        $statement = $this->pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), leased_until) FROM processing_jobs WHERE id = ?');
        $statement->execute([$job->id()]);
        $seconds = (int) $statement->fetchColumn();
        self::assertGreaterThanOrEqual(119, $seconds);
        self::assertLessThanOrEqual(121, $seconds);
    }

    public function testConcurrentPhpWorkersClaimOnlyOneJob(): void
    {
        $jobId = $this->insertJob(3);
        $script = tempnam(sys_get_temp_dir(), 'queue-claim-worker-');
        self::assertNotFalse($script);
        $barrier = tempnam(sys_get_temp_dir(), 'queue-claim-barrier-');
        self::assertNotFalse($barrier);
        unlink($barrier);

        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1] . '/vendor/autoload.php';
$pdo = new \PDO((string) getenv('TEST_DB_DSN'), getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$repository = new \App\Repositories\ProcessingJobRepository($pdo);
fwrite(STDOUT, "ready\n");
$barrierDeadline = microtime(true) + 5.0;
while (!file_exists($argv[3])) {
    if (microtime(true) >= $barrierDeadline) {
        fwrite(STDERR, "barrier timeout\\n");
        exit(3);
    }
    usleep(1000);
}
$job = $repository->claimNext('media', $argv[2], 120);
fwrite(STDOUT, json_encode($job === null ? null : $job->id(), JSON_THROW_ON_ERROR) . PHP_EOL);
PHP
        );

        $workers = [];
        try {
            foreach (['queue-concurrent-a', 'queue-concurrent-b'] as $workerId) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 2), $workerId, $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                self::assertIsResource($process);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }
            foreach ($workers as $worker) {
                self::assertSame('ready', trim((string) fgets($worker['stdout'])));
            }
            touch($barrier);

            $claimedIds = [];
            foreach ($workers as $worker) {
                $stdout = stream_get_contents($worker['stdout']);
                $stderr = stream_get_contents($worker['stderr']);
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                self::assertSame(0, proc_close($worker['process']));
                self::assertSame('', $stderr);
                $claimedIds[] = json_decode(trim((string) $stdout), true, 512, JSON_THROW_ON_ERROR);
            }

            sort($claimedIds);
            self::assertSame([null, $jobId], $claimedIds);
        } finally {
            if (!file_exists($barrier)) {
                touch($barrier);
            }
            foreach ($workers as $worker) {
                foreach (['stdout', 'stderr'] as $pipe) {
                    if (is_resource($worker[$pipe])) {
                        fclose($worker[$pipe]);
                    }
                }
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    proc_close($worker['process']);
                }
            }
            @unlink($script);
            @unlink($barrier);
        }
    }

    private function insertExpiredExhaustedJob(): void
    {
        $jobId = $this->insertJob(1);
        $this->pdo->prepare("UPDATE processing_jobs SET status = 'running', attempts = max_attempts, worker_id = 'abandoned-worker', lease_token_hash = REPEAT('a', 64), leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?")
            ->execute([$jobId]);
    }

    private function insertJob(int $maxAttempts): int
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())")
            ->execute(['queue-hardening-' . $suffix, 'Queue hardening ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')
            ->execute(['Queue hardening test', $suffix . '@example.test', 'not-a-real-hash', $planId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO projects (user_id, name, status) VALUES (?, ?, ?)')
            ->execute([$userId, 'Queue hardening project', 'queued']);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO processing_jobs (queue_name, type, project_id, payload_json, idempotency_key, max_attempts, available_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute(['media', 'probe_source', $projectId, '{}', hash('sha256', $suffix), $maxAttempts]);

        return (int) $this->pdo->lastInsertId();
    }

    private function status(int $jobId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM processing_jobs WHERE id = ?');
        $statement->execute([$jobId]);

        return (string) $statement->fetchColumn();
    }
}
