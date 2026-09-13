<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\JobDispatcher;
use App\Core\Migrator;
use App\Services\DatabaseJobDispatcher;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseJobDispatcherTest extends TestCase
{
    private PDO $pdo;

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
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->exec('DELETE FROM processing_jobs');
        $this->pdo->exec("DELETE FROM users WHERE name = 'Dispatcher test'");
        $this->pdo->exec("DELETE FROM plans WHERE slug LIKE 'dispatcher-test-%'");
    }
    public function testDispatchIsIdempotentAndSerializesOnlyThePayload(): void
    {
        $dispatcher = new DatabaseJobDispatcher($this->pdo, 'media', 3);
        self::assertInstanceOf(JobDispatcher::class, $dispatcher);
        $projectId = $this->createProject();

        $first = $dispatcher->dispatch('probe_source', $projectId, ['source_id' => 77], 'browser-job-key');
        $second = $dispatcher->dispatch('probe_source', $projectId, ['source_id' => 77], 'browser-job-key');

        self::assertSame($first, $second);
        $statement = $this->pdo->prepare('SELECT queue_name, type, project_id, payload_json, idempotency_key, max_attempts, status FROM processing_jobs WHERE id = ?');
        $statement->execute([$first]);
        self::assertSame([
            'queue_name' => 'media',
            'type' => 'probe_source',
            'project_id' => (string) $projectId,
            'payload_json' => '{"source_id":77}',
            'idempotency_key' => hash('sha256', 'browser-job-key'),
            'max_attempts' => '3',
            'status' => 'queued',
        ], $statement->fetch(PDO::FETCH_ASSOC));
    }

    public function testDispatchRejectsASemanticConflictForTheSameIdempotencyKey(): void
    {
        $dispatcher = new DatabaseJobDispatcher($this->pdo, 'media', 3);
        $projectId = $this->createProject();
        $dispatcher->dispatch('analyze_video', $projectId, [
            'analysis_id' => 11,
            'source_id' => 22,
            'reservation_id' => 33,
        ], 'analysis-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Job idempotency conflict.');
        $dispatcher->dispatch('analyze_video', $projectId, [
            'analysis_id' => 11,
            'source_id' => 22,
            'reservation_id' => 34,
        ], 'analysis-key');
    }

    public function testDuplicateRaceUsesACurrentReadInsideAnOlderCallerSnapshot(): void
    {
        $projectId = $this->createProject();
        $winner = new DatabaseJobDispatcher($this->pdo, 'media', 3);
        $sameSnapshot = $this->connection();
        $sameSnapshot->beginTransaction();
        $sameSnapshot->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn();
        $winnerId = $winner->dispatch('probe_source', $projectId, ['source_id' => 77], 'snapshot-same');

        try {
            $replayedId = (new DatabaseJobDispatcher($sameSnapshot, 'media', 3))
                ->dispatch('probe_source', $projectId, ['source_id' => 77], 'snapshot-same');
            self::assertSame($winnerId, $replayedId);
        } finally {
            $sameSnapshot->rollBack();
        }

        $conflictSnapshot = $this->connection();
        $conflictSnapshot->beginTransaction();
        $conflictSnapshot->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn();
        $winner->dispatch('analyze_video', $projectId, [
            'analysis_id' => 11,
            'source_id' => 22,
            'reservation_id' => 33,
        ], 'snapshot-conflict');
        try {
            (new DatabaseJobDispatcher($conflictSnapshot, 'media', 3))->dispatch('analyze_video', $projectId, [
                'analysis_id' => 11,
                'source_id' => 22,
                'reservation_id' => 34,
            ], 'snapshot-conflict');
            self::fail('The semantic conflict in an older snapshot was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Job idempotency conflict.', $exception->getMessage());
        } finally {
            $conflictSnapshot->rollBack();
        }
    }

    private function createProject(): int
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)")
            ->execute(['dispatcher-test-' . $suffix, 'Dispatcher test ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')
            ->execute(['Dispatcher test', $suffix . '@example.test', 'not-a-real-hash', $planId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO projects (user_id, name, status) VALUES (?, ?, ?)')
            ->execute([$userId, 'Dispatcher project', 'queued']);

        return (int) $this->pdo->lastInsertId();
    }

    private function connection(): PDO
    {
        return new PDO(
            (string) getenv('TEST_DB_DSN'),
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
}
