<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\JobDispatcher;
use App\Core\Migrator;
use App\Media\DirectUrlValidator;
use App\Media\StoredObject;
use App\Media\UploadValidator;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\ProjectIntakeService;
use App\Storage\LocalPrivateStorage;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectIntakeServiceHardeningTest extends TestCase
{
    private PDO $pdo;
    private PDO $secondPdo;
    private int $userId;
    private int $otherUserId;
    private string $root;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, $options);
        $this->secondPdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, $options);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $this->userId = $this->createUser($this->pdo, $planId, 'intake-main');
        $this->otherUserId = $this->createUser($this->pdo, $planId, 'intake-other');
        $this->root = sys_get_temp_dir() . '/intake-hardening-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->userId, $this->otherUserId)) {
            $statement = $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)');
            $statement->execute([$this->userId, $this->otherUserId]);
        }
        if (isset($this->root) && is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $path) {
                $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
            }
            @rmdir($this->root);
        }
    }

    public function testRegistersValidatedDirectUrlWithoutDownloadingIt(): void
    {
        $service = $this->service($this->pdo);
        $receipt = $service->fromDirectUrl($this->userId, [
            'name' => 'Remote source',
            'idempotency_key' => 'direct-url-key',
            'source_url' => 'https://cdn.example.test/videos/interview.mp4?quality=high',
        ]);

        self::assertTrue($receipt->created());
        $source = $this->sourceRow($receipt->projectId());
        self::assertSame('direct_url', $source['source_type']);
        self::assertSame('pending', $source['status']);
        self::assertSame('cdn.example.test', $source['source_host']);
        self::assertSame('https://cdn.example.test/videos/interview.mp4?quality=high', $source['source_url']);
        self::assertSame(1, $this->jobCount($receipt->projectId()));
        self::assertSame([], glob($this->root . '/media/*') ?: []);
    }

    public function testCompensatesNewUploadObjectWhenTheDatabaseTransactionFails(): void
    {
        $jobs = new class implements JobDispatcher {
            public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
            {
                throw new \RuntimeException('Simulated job persistence failure.');
            }
        };
        $service = $this->service($this->pdo, $jobs);

        try {
            $service->fromUpload($this->userId, ['name' => 'Rollback source', 'idempotency_key' => 'rollback-key'], $this->file('rollback.mp4'));
            self::fail('The simulated persistence failure did not escape.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated job persistence failure.', $exception->getMessage());
        }
        self::assertSame(0, $this->projectCountForUser($this->userId));
        self::assertSame([], glob($this->root . '/media/users/' . $this->userId . '/uploads/*') ?: []);
    }

    public function testSourceLookupNeverReturnsAnotherUsersProject(): void
    {
        $receipt = $this->service($this->pdo)->fromUpload($this->userId, ['name' => 'Owned source', 'idempotency_key' => 'owner-key'], $this->file('owned.mp4'));
        $sources = new ProjectSourceRepository($this->pdo);

        self::assertNotNull($sources->findForOwnedProject($receipt->projectId(), $this->userId));
        self::assertNull($sources->findForOwnedProject($receipt->projectId(), $this->otherUserId));
    }

    public function testTwoIndependentPdoConnectionsKeepOneIntakeProjectSourceAndJob(): void
    {
        $input = ['name' => 'Shared intake', 'idempotency_key' => 'multi-pdo-key'];
        $first = $this->service($this->pdo)->fromUpload($this->userId, $input, $this->file('first.mp4'));
        $second = $this->service($this->secondPdo)->fromUpload($this->userId, $input, $this->file('second.mp4'));

        self::assertTrue($first->created());
        self::assertFalse($second->created());
        self::assertSame($first->projectId(), $second->projectId());
        self::assertSame(1, $this->projectCountForUser($this->userId));
        self::assertSame(1, $this->sourceCount($first->projectId()));
        self::assertSame(1, $this->jobCount($first->projectId()));
        self::assertCount(1, glob($this->root . '/media/users/' . $this->userId . '/uploads/*') ?: []);
    }

    private function service(PDO $pdo, ?JobDispatcher $jobs = null): ProjectIntakeService
    {
        $jobs ??= new class($pdo) implements JobDispatcher {
            public function __construct(private PDO $pdo) {}
            public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
            {
                $statement = $this->pdo->prepare('INSERT INTO processing_jobs (type, project_id, payload_json, idempotency_key, available_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
                $statement->execute([$type, $projectId, '{}', $idempotencyKey]);
                return (int) $this->pdo->lastInsertId();
            }
        };
        return new ProjectIntakeService(
            $pdo,
            new ProjectRepository($pdo),
            new ProjectSourceRepository($pdo),
            new LocalPrivateStorage($this->root . '/media', 1024, static fn (string $path): bool => true),
            $jobs,
            new UploadValidator(1024, static fn (string $path): string => 'video/mp4'),
            new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1'])
        );
    }

    /** @return array<string, mixed> */
    private function file(string $name): array
    {
        $path = $this->root . '/' . bin2hex(random_bytes(8)) . '.mp4';
        file_put_contents($path, "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41");
        return ['name' => $name, 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
    }

    private function createUser(PDO $pdo, int $planId, string $prefix): int
    {
        $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Intake', $prefix . '-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function sourceRow(int $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT source_type, source_url, source_host, status FROM project_sources WHERE project_id = ?');
        $statement->execute([$projectId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function projectCountForUser(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE user_id = ?');
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }

    private function sourceCount(int $projectId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM project_sources WHERE project_id = ?');
        $statement->execute([$projectId]);
        return (int) $statement->fetchColumn();
    }

    private function jobCount(int $projectId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM processing_jobs WHERE project_id = ?');
        $statement->execute([$projectId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function allFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path) {
            if ($path->isFile()) {
                $files[] = $path->getPathname();
            }
        }
        return $files;
    }
}
