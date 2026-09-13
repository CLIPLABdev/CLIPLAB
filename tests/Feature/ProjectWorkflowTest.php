<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\MediaProcessor;
use App\Core\Migrator;
use App\Media\DirectUrlValidator;
use App\Media\MediaMetadata;
use App\Media\ProjectSource;
use App\Media\UploadValidator;
use App\Queue\FetchAndProbeHandler;
use App\Queue\LeaseProcessingEffectGuard;
use App\Queue\ProbeSourceHandler;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\DatabaseJobDispatcher;
use App\Services\ProjectIntakeService;
use App\Services\ProjectStatusService;
use App\Services\QueueWorker;
use App\Storage\LocalPrivateStorage;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectWorkflowTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private int $otherUserId;
    private string $root;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') { self::markTestSkipped('TEST_DB_DSN is not configured.'); }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $this->userId = $this->insertUser($planId, 'workflow');
        $this->otherUserId = $this->insertUser($planId, 'other');
        $this->root = sys_get_temp_dir() . '/clipforge-workflow-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->userId, $this->otherUserId]);
        }
        if (isset($this->root)) { $this->removeTree($this->root); }
    }

    public function testUploadCreatesQueuedProjectWhichWorkerMarksReady(): void
    {
        $storage = new LocalPrivateStorage($this->root . '/media', 1024 * 1024, static fn (): bool => true);
        $projects = new ProjectRepository($this->pdo);
        $sources = new ProjectSourceRepository($this->pdo);
        $intake = new ProjectIntakeService(
            $this->pdo,
            $projects,
            $sources,
            $storage,
            new DatabaseJobDispatcher($this->pdo),
            new UploadValidator(1024 * 1024, static fn (): string => 'video/mp4'),
            new DirectUrlValidator(static fn (): array => ['1.1.1.1'])
        );
        $path = $this->root . '/valid.mp4';
        file_put_contents($path, base64_decode(trim((string) file_get_contents(dirname(__DIR__) . '/Fixtures/valid-mp4.base64')), true));

        $receipt = $intake->fromUpload($this->userId, ['name' => 'Entrevista', 'idempotency_key' => 'workflow-upload'], [
            'name' => 'entrevista.mp4', 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK,
        ]);
        self::assertSame('queued', $receipt->status());

        $processor = new class implements MediaProcessor {
            public function inspect(ProjectSource $source): MediaMetadata
            {
                return new MediaMetadata(91, 1280, 720, 'h264', 'aac', true);
            }
        };
        $effects = new LeaseProcessingEffectGuard($this->pdo);
        $handlers = [
            'probe_source' => new ProbeSourceHandler($projects, $sources, $processor, $effects),
            'fetch_and_probe' => new FetchAndProbeHandler($projects, $sources, new \stdClass(), $storage, $processor, $effects),
        ];
        $report = (new QueueWorker(new ProcessingJobRepository($this->pdo), $handlers, 'workflow-test', 300))->run('media', 1, 50);
        $status = (new ProjectStatusService($projects))->forOwnedProject($receipt->projectId(), $this->userId);

        self::assertSame(1, $report->completed);
        self::assertNotNull($status);
        self::assertSame('ready', $status['status']);
        self::assertSame(100, $status['progress']);
        self::assertSame(1280, $status['media']['width']);
        self::assertNull((new ProjectStatusService($projects))->forOwnedProject($receipt->projectId(), $this->otherUserId));
    }

    private function insertUser(int $planId, string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Workflow', $prefix . '-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
