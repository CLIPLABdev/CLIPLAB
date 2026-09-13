<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Plans\PlanLimitExceeded;
use App\Services\PlanQuotaService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class PlanQuotaServiceTest extends TestCase
{
    private PDO $pdo;
    private PlanQuotaService $service;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seedFixtures();
        $this->service = new PlanQuotaService($this->pdo);
        $this->now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }

    public function testSnapshotUsesLedgerAndCountsOnlyOwnedReferencedArtifacts(): void
    {
        $snapshot = $this->service->snapshotForUser(7, $this->now);

        self::assertSame('pro', $snapshot['plan']['slug']);
        self::assertSame(6, $snapshot['credits']);
        self::assertSame(2, $snapshot['minutes_used']);
        self::assertSame(3, $snapshot['minutes_remaining']);
        self::assertSame(550, $snapshot['storage_bytes']);
        self::assertSame(450, $snapshot['storage_remaining_bytes']);
        self::assertSame(100, $snapshot['plan']['features']['limits']['max_upload_bytes']);
        self::assertSame(1000, $snapshot['plan']['features']['limits']['storage_bytes']);
    }

    public function testLibraryAssetsCountOnlyOwnedStoredBytesWithoutVirtualThumbnails(): void
    {
        $this->pdo->exec("INSERT INTO user_brand_logos VALUES (1,7,40),(2,8,500)");
        $this->pdo->exec("INSERT INTO clip_thumbnails VALUES (1,7,'ready','candidate.jpg',60),(2,7,'pending',NULL,900),(3,8,'ready','other.jpg',800)");
        $snapshot = $this->service->snapshotForUser(7, $this->now);
        self::assertSame(650, $snapshot['storage_bytes']);
        self::assertSame(350, $snapshot['storage_remaining_bytes']);
        $this->pdo->beginTransaction();
        try {
            $this->service->assertAdditionalStorageAvailable(7, 350);
            $this->service->assertAdditionalStorageAvailable(7, 351);
            self::fail('Library assets must consume storage quota.');
        } catch (PlanLimitExceeded $e) {
            self::assertSame(650, $e->used());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testStorageAdmissionRequiresTransactionAndRejectsOnlyOverflow(): void
    {
        $this->expectException(\LogicException::class);
        $this->service->assertAdditionalStorageAvailable(7, 1);
    }

    public function testStorageAdmissionAllowsExactRemainderAndReportsOverflow(): void
    {
        $this->pdo->beginTransaction();
        $this->service->assertAdditionalStorageAvailable(7, 450);
        $this->pdo->rollBack();

        $this->pdo->beginTransaction();
        try {
            $this->service->assertAdditionalStorageAvailable(7, 451);
            self::fail('Expected the storage quota to reject an overflow.');
        } catch (PlanLimitExceeded $exception) {
            self::assertSame('storage_limit_exceeded', $exception->errorCode());
            self::assertSame(1000, $exception->limit());
            self::assertSame(550, $exception->used());
            self::assertSame(451, $exception->requested());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testUploadAdmissionUsesPlanLimitUnderTransaction(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->service->assertUploadBytesAllowed(7, 101);
            self::fail('Expected the per-file upload limit to reject an overflow.');
        } catch (PlanLimitExceeded $exception) {
            self::assertSame('upload_limit_exceeded', $exception->errorCode());
            self::assertSame(100, $exception->limit());
            self::assertSame(101, $exception->requested());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testSuspendedAccountCannotAdmitNewConsumption(): void
    {
        $this->pdo->exec("UPDATE users SET status = 'suspended' WHERE id = 7");
        $this->pdo->beginTransaction();
        try {
            $this->service->assertAdditionalStorageAvailable(7, 1);
            self::fail('Expected the suspended account to be denied.');
        } catch (PlanLimitExceeded $exception) {
            self::assertSame('account_inactive', $exception->errorCode());
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testProcessingUsageRoundsEachVideoAndIsIdempotent(): void
    {
        $this->pdo->beginTransaction();
        self::assertSame(3, $this->service->assertProcessingMinutesAvailable(7, 13, 121, $this->now));
        $this->service->recordProcessingUsage(7, 13, 121, $this->now);
        $this->pdo->commit();

        self::assertSame(5, $this->service->snapshotForUser(7, $this->now)['minutes_used']);

        $this->pdo->beginTransaction();
        self::assertSame(3, $this->service->assertProcessingMinutesAvailable(7, 13, 121, $this->now));
        $this->service->recordProcessingUsage(7, 13, 121, $this->now);
        $this->pdo->commit();
        self::assertSame(5, $this->service->snapshotForUser(7, $this->now)['minutes_used']);
    }

    public function testProcessingOverflowDoesNotRecordUsage(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->service->assertProcessingMinutesAvailable(7, 14, 181, $this->now);
            self::fail('Expected the monthly processing quota to reject an overflow.');
        } catch (PlanLimitExceeded $exception) {
            self::assertSame('monthly_minutes_exceeded', $exception->errorCode());
            self::assertSame(5, $exception->limit());
            self::assertSame(2, $exception->used());
            self::assertSame(4, $exception->requested());
        } finally {
            $this->pdo->rollBack();
        }

        $project = $this->pdo->query('SELECT processed_duration_seconds, usage_recorded_at FROM projects WHERE id = 14')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $project['processed_duration_seconds']);
        self::assertNull($project['usage_recorded_at']);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE user_brand_logos (id INTEGER PRIMARY KEY, user_id INTEGER, size_bytes INTEGER)');
        $this->pdo->exec('CREATE TABLE clip_thumbnails (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, object_key TEXT NULL, size_bytes INTEGER)');
        $this->pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, price_cents INTEGER, monthly_minutes INTEGER, credits INTEGER, features TEXT, is_active INTEGER)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, plan_id INTEGER, credits INTEGER, status TEXT)');
        $this->pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER, processed_duration_seconds INTEGER DEFAULT 0, usage_recorded_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE project_sources (id INTEGER PRIMARY KEY, project_id INTEGER, object_key TEXT NULL, size_bytes INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE clips (id INTEGER PRIMARY KEY, project_id INTEGER, output_file TEXT NULL, output_size_bytes INTEGER NULL, thumbnail TEXT NULL, thumbnail_size_bytes INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER, balance_after INTEGER, created_at TEXT)');
    }

    private function seedFixtures(): void
    {
        $features = json_encode([
            'exports_hd' => true,
            'priority_processing' => true,
            'team_access' => false,
            'limits' => ['max_upload_bytes' => 100, 'storage_bytes' => 1000],
        ], JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('INSERT INTO plans VALUES (1, ?, ?, 1990, 5, 10, ?, 1)');
        $statement->execute(['pro', 'Pro', $features]);
        $this->pdo->exec("INSERT INTO users VALUES (7, 'Ana', 'ana@example.test', 1, 9, 'active'), (8, 'Bia', 'bia@example.test', 1, 99, 'active')");
        $this->pdo->exec("INSERT INTO credit_transactions VALUES (1, 7, 8, '2026-09-01 10:00:00'), (2, 7, 6, '2026-09-02 10:00:00')");
        $this->pdo->exec("INSERT INTO projects VALUES (10, 7, 61, '2026-09-01 09:00:00'), (11, 7, 120, '2026-08-31 23:59:59'), (12, 8, 600, '2026-09-01 09:00:00'), (13, 7, 0, NULL), (14, 7, 0, NULL)");
        $this->pdo->exec("INSERT INTO project_sources VALUES (1, 10, 'source.mp4', 300), (2, 10, NULL, 800), (3, 12, 'other.mp4', 999)");
        $this->pdo->exec("INSERT INTO clips VALUES (1, 10, 'clip.mp4', 200, 'thumb.jpg', 50), (2, 10, NULL, 700, NULL, 900), (3, 12, 'other-clip.mp4', 999, NULL, NULL)");
    }
}
