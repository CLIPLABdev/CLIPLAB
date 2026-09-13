<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\ProcessingJobRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProcessingJobRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ProcessingJobRepository $repository;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        $this->pdo = $this->connection();
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
        $this->pdo->exec("DELETE FROM users WHERE name = 'Queue test'");
        $this->pdo->exec("DELETE FROM plans WHERE slug LIKE 'queue-test-%'");
    }
    public function testOnlyOneWorkerClaimsAnEligibleJob(): void
    {
        $jobId = $this->insertQueuedJob();
        $first = $this->repository->claimNext('media', 'worker-a', 120);
        $second = (new ProcessingJobRepository($this->connection()))->claimNext('media', 'worker-b', 120);

        self::assertSame($jobId, $first?->id());
        self::assertNull($second);
    }

    public function testExpiredLeaseCanBeReclaimedAndOldTokenCannotComplete(): void
    {
        $jobId = $this->insertQueuedJob();
        $old = $this->repository->claimNext('media', 'worker-old', 60);
        self::assertNotNull($old);
        $this->pdo->prepare("UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?")
            ->execute([$jobId]);

        $new = (new ProcessingJobRepository($this->connection()))->claimNext('media', 'worker-new', 120);

        self::assertNotNull($new);
        self::assertSame($jobId, $new->id());
        self::assertFalse($this->repository->complete($old));
        self::assertTrue((new ProcessingJobRepository($this->connection()))->complete($new));
    }

    public function testRetryUsesExponentialBackoffAndExhaustedJobFails(): void
    {
        $jobId = $this->insertQueuedJob(2);
        $first = $this->repository->claimNext('media', 'worker-a', 120);
        self::assertNotNull($first);
        $availableAt = new DateTimeImmutable('+15 seconds', new DateTimeZone('UTC'));

        self::assertTrue($this->repository->retry($first, 'processor_unavailable', 'Processador temporariamente indisponível.', $availableAt));
        $row = $this->jobRow($jobId);
        self::assertSame('retry', $row['status']);
        self::assertSame('processor_unavailable', $row['last_error_code']);
        self::assertSame('Processador temporariamente indisponível.', $row['last_error_message']);
        self::assertSame(15, (int) $this->pdo->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) FROM processing_jobs WHERE id = {$jobId}")->fetchColumn(), 'first retry must wait 15 seconds');

        $this->pdo->prepare('UPDATE processing_jobs SET available_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$jobId]);
        $second = $this->repository->claimNext('media', 'worker-a', 120);
        self::assertNotNull($second);
        self::assertTrue($this->repository->retry($second, 'processor_unavailable', 'Processador temporariamente indisponível.', $availableAt));
        self::assertSame('failed', $this->jobRow($jobId)['status']);
    }

    public function testDeferKeepsAnExhaustedJobEligibleForPersistenceReconciliation(): void
    {
        $jobId = $this->insertQueuedJob(1);
        $job = $this->repository->claimNext('media', 'worker-a', 120);
        self::assertNotNull($job);

        self::assertTrue($this->repository->defer(
            $job,
            'processing_persistence_failed',
            'Não foi possível salvar o processamento agora. Tente novamente.',
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        ));
        self::assertSame('retry', $this->jobRow($jobId)['status']);

        $reclaimed = $this->repository->claimNext('media', 'worker-b', 120);
        self::assertNotNull($reclaimed);
        self::assertSame($jobId, $reclaimed->id());
        self::assertSame(2, $reclaimed->attempts());
    }

    public function testTransitionRejectsCodesOutsideThePublicAllowlist(): void
    {
        $job = $this->repository->claimNext('media', 'worker-a', 120);
        self::assertNull($job);

        $this->insertQueuedJob();
        $job = $this->repository->claimNext('media', 'worker-a', 120);
        self::assertNotNull($job);

        $this->expectException(\InvalidArgumentException::class);
        $this->repository->fail($job, 'untrusted_database_detail', 'Internal details must not be public.');
    }

    private function insertQueuedJob(int $maxAttempts = 3): int
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)")
            ->execute(['queue-test-' . $suffix, 'Queue test ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')
            ->execute(['Queue test', $suffix . '@example.test', 'not-a-real-hash', $planId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO projects (user_id, name, status) VALUES (?, ?, ?)')
            ->execute([$userId, 'Queue project', 'queued']);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO processing_jobs (queue_name, type, project_id, payload_json, idempotency_key, max_attempts, available_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute(['media', 'probe_source', $projectId, '{"source_id":1}', hash('sha256', $suffix), $maxAttempts]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function jobRow(int $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT status, last_error_code, last_error_message FROM processing_jobs WHERE id = ?');
        $statement->execute([$jobId]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function connection(): PDO
    {
        return new PDO(
            (string) getenv('TEST_DB_DSN'),
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}
