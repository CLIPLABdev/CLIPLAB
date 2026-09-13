<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Plans\PlanLimitExceeded;
use App\Services\PlanQuotaService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class PlanQuotaConcurrencyTest extends TestCase
{
    private PDO $first;
    private PDO $second;
    private int $planId;
    private int $ownerId;
    private int $otherId;
    private int $projectId;

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
        $this->first = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, $options);
        $this->second = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, $options);
        (new Migrator($this->first, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $suffix = bin2hex(random_bytes(7));
        $features = '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":1000,"storage_bytes":1000}}';
        $this->first->prepare('INSERT INTO plans (slug, name, monthly_minutes, credits, features) VALUES (?, ?, 10, 10, ?)')->execute(['quota-race-' . $suffix, 'Quota race ' . $suffix, $features]);
        $this->planId = (int) $this->first->lastInsertId();
        $this->ownerId = $this->user('quota-owner-' . $suffix);
        $this->otherId = $this->user('quota-other-' . $suffix);
        $this->first->prepare("INSERT INTO projects (user_id, ingest_key, name, status) VALUES (?, ?, 'Quota project', 'ready')")->execute([$this->ownerId, hash('sha256', 'quota-' . $suffix)]);
        $this->projectId = (int) $this->first->lastInsertId();
        $this->first->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, mime_type, size_bytes, status) VALUES (?, 'upload', 'local', ?, 'video/mp4', 600, 'ready')")->execute([$this->projectId, 'quota/' . $suffix . '/source.mp4']);
    }

    protected function tearDown(): void
    {
        foreach (['first', 'second'] as $connection) {
            if (isset($this->{$connection}) && $this->{$connection}->inTransaction()) {
                $this->{$connection}->rollBack();
            }
        }
        if (isset($this->first, $this->ownerId, $this->otherId)) {
            $this->first->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->ownerId, $this->otherId]);
            $this->first->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
        }
    }

    public function testCurrentLockingReadSeesCommittedBytesAfterAnOlderRepeatableReadSnapshot(): void
    {
        $this->second->beginTransaction();
        $this->second->query('SELECT COUNT(*) FROM project_sources')->fetchColumn();

        $this->first->beginTransaction();
        (new PlanQuotaService($this->first))->assertAdditionalStorageAvailable($this->ownerId, 400);
        $this->first->prepare("INSERT INTO projects (user_id, ingest_key, name, status) VALUES (?, ?, 'Committed quota project', 'ready')")->execute([$this->ownerId, hash('sha256', 'quota-committed-' . $this->projectId)]);
        $committedProjectId = (int) $this->first->lastInsertId();
        $this->first->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, mime_type, size_bytes, status) VALUES (?, 'upload', 'local', 'quota/committed.mp4', 'video/mp4', 400, 'ready')")->execute([$committedProjectId]);
        $this->first->commit();

        try {
            (new PlanQuotaService($this->second))->assertAdditionalStorageAvailable($this->ownerId, 1);
            self::fail('A stale repeatable-read snapshot allowed storage oversubscription.');
        } catch (PlanLimitExceeded $exception) {
            self::assertSame('storage_limit_exceeded', $exception->errorCode());
            self::assertSame(1000, $exception->used());
        } finally {
            $this->second->rollBack();
        }

        $this->second->beginTransaction();
        (new PlanQuotaService($this->second))->assertAdditionalStorageAvailable($this->otherId, 1000);
        $this->second->rollBack();
        self::assertTrue(true);
    }

    private function user(string $prefix): int
    {
        $statement = $this->first->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Quota user', $prefix . '@example.test', 'x', $this->planId]);

        return (int) $this->first->lastInsertId();
    }
}
