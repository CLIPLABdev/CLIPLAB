<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\JobDispatcher;
use App\Core\Migrator;
use App\Media\DirectUrlValidator;
use App\Media\UploadValidator;
use App\Plans\PlanLimitExceeded;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\PlanQuotaService;
use App\Services\ProjectIntakeService;
use App\Storage\LocalPrivateStorage;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectIntakeServiceTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private string $root;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') { self::markTestSkipped('TEST_DB_DSN is not configured.'); }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $suffix = bin2hex(random_bytes(7));
        $features = '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":1024,"storage_bytes":1048576}}';
        $this->pdo->prepare('INSERT INTO plans (slug, name, monthly_minutes, credits, features) VALUES (?, ?, 30, 10, ?)')->execute(['intake-' . $suffix, 'Intake ' . $suffix, $features]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Intake', 'intake-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $this->planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->root = sys_get_temp_dir() . '/intake-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
        foreach (glob($this->root . '/media/users/' . $this->userId . '/uploads/*') ?: [] as $file) { @unlink($file); }
        @rmdir($this->root . '/media/users/' . $this->userId . '/uploads'); @rmdir($this->root . '/media/users/' . $this->userId); @rmdir($this->root . '/media/users'); @rmdir($this->root . '/media'); @rmdir($this->root);
    }

    public function testRepeatedUploadReceiptDoesNotDuplicateRowsOrJobs(): void
    {
        $service = $this->service();
        $input = ['name' => 'Podcast 01', 'idempotency_key' => 'browser-key'];
        $first = $service->fromUpload($this->userId, $input, $this->file());
        $second = $service->fromUpload($this->userId, $input, $this->file());
        self::assertTrue($first->created()); self::assertFalse($second->created()); self::assertSame($first->projectId(), $second->projectId());
        foreach (['project_sources', 'processing_jobs'] as $table) {
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE project_id = ?"); $statement->execute([$first->projectId()]); self::assertSame(1, (int) $statement->fetchColumn());
        }
    }

    public function testAutomaticExportChoiceIsPersistedOnlyForNewReceipts(): void
    {
        $service = $this->service();
        $first = $service->fromUpload($this->userId, ['name' => 'Automatic', 'idempotency_key' => 'auto-upload', 'auto_render_requested' => '1'], $this->file());
        $replay = $service->fromUpload($this->userId, ['name' => 'Automatic', 'idempotency_key' => 'auto-upload', 'auto_render_requested' => '0'], $this->file());
        $legacy = $service->fromDirectUrl($this->userId, ['name' => 'Manual', 'idempotency_key' => 'manual-url', 'source_url' => 'https://example.test/video.mp4']);
        $automaticUrl = $service->fromDirectUrl($this->userId, ['name' => 'Automatic URL', 'idempotency_key' => 'auto-url', 'source_url' => 'https://example.test/video.mp4', 'auto_render_requested' => '1']);
        self::assertFalse($replay->created());
        $query = $this->pdo->prepare('SELECT auto_render_requested FROM projects WHERE id = ?');
        foreach ([$first->projectId() => 1, $legacy->projectId() => 0, $automaticUrl->projectId() => 1] as $id => $expected) {
            $query->execute([$id]);
            self::assertSame($expected, (int) $query->fetchColumn());
        }
    }

    public function testPlanUploadLimitRejectsWithoutPersistingRowsOrObject(): void
    {
        $this->setLimits(16, 1048576);

        try {
            $this->service()->fromUpload($this->userId, ['name' => 'Large upload', 'idempotency_key' => 'large-key'], $this->file());
            self::fail('Expected the plan upload limit to reject the file.');
        } catch (PlanLimitExceeded $exception) {
            self::assertSame('upload_limit_exceeded', $exception->errorCode());
        }
        self::assertSame(0, $this->fixtureCount('projects'));
        self::assertSame([], glob($this->root . '/media/users/' . $this->userId . '/uploads/*') ?: []);
    }

    public function testStorageLimitRollsBackProjectAndDeletesNewObject(): void
    {
        $this->setLimits(1024, 16);

        try {
            $this->service()->fromUpload($this->userId, ['name' => 'No storage', 'idempotency_key' => 'storage-key'], $this->file());
            self::fail('Expected the storage limit to reject the file.');
        } catch (PlanLimitExceeded $exception) {
            self::assertSame('storage_limit_exceeded', $exception->errorCode());
        }
        self::assertSame(0, $this->fixtureCount('projects'));
        self::assertSame(0, $this->fixtureCount('project_sources'));
        self::assertSame(0, $this->fixtureCount('processing_jobs'));
        self::assertSame([], glob($this->root . '/media/users/' . $this->userId . '/uploads/*') ?: []);
    }

    private function service(): ProjectIntakeService
    {
        $jobs = new class($this->pdo) implements JobDispatcher { public function __construct(private PDO $pdo) {} public function dispatch(string $type, int $projectId, array $payload, string $key): int { $s = $this->pdo->prepare('INSERT INTO processing_jobs (type, project_id, payload_json, idempotency_key, available_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())'); $s->execute([$type, $projectId, '{}', $key]); return (int) $this->pdo->lastInsertId(); } };
        return new ProjectIntakeService($this->pdo, new ProjectRepository($this->pdo), new ProjectSourceRepository($this->pdo), new LocalPrivateStorage($this->root . '/media', 1024, static fn (string $path): bool => true), $jobs, new UploadValidator(1024, static fn (string $path): string => 'video/mp4'), new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']), new PlanQuotaService($this->pdo));
    }

    /** @return array<string, mixed> */
    private function file(): array
    {
        $path = $this->root . '/' . bin2hex(random_bytes(4)) . '.mp4'; file_put_contents($path, "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41");
        return ['name' => 'episode.mp4', 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
    }

    private function setLimits(int $uploadBytes, int $storageBytes): void
    {
        $features = json_encode(['exports_hd' => false, 'priority_processing' => false, 'team_access' => false, 'limits' => ['max_upload_bytes' => $uploadBytes, 'storage_bytes' => $storageBytes]], JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('UPDATE plans SET features = ? WHERE id = ?');
        $statement->execute([$features, $this->planId]);
    }

    private function fixtureCount(string $table): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE project_id IN (SELECT id FROM projects WHERE user_id = ?)');
        if ($table === 'projects') {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE user_id = ?');
        }
        $statement->execute([$this->userId]);

        return (int) $statement->fetchColumn();
    }
}
