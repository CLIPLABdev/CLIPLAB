<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProcessJobsCompositionTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private string $mediaRoot;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') { self::markTestSkipped('TEST_DB_DSN is not configured.'); }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['CLI composition', 'cli-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->mediaRoot = sys_get_temp_dir() . '/clipforge-cli-' . bin2hex(random_bytes(6));
        mkdir($this->mediaRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->userId)) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        if (isset($this->mediaRoot)) { @rmdir($this->mediaRoot); }
    }

    public function testKnownProbeJobUsesTheComposedHandlerInsteadOfUnsupportedFallback(): void
    {
        $this->pdo->prepare("INSERT INTO projects (user_id, ingest_key, name, status, progress) VALUES (?, ?, 'CLI project', 'queued', 0)")
            ->execute([$this->userId, hash('sha256', 'cli-' . $this->userId)]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, status) VALUES (?, 'upload', 'local', 'missing.mp4', 'video.mp4', 'mp4', 'video/mp4', 32, 'stored')")
            ->execute([$projectId]);
        $this->pdo->prepare("INSERT INTO processing_jobs (type, project_id, payload_json, idempotency_key, available_at) VALUES ('probe_source', ?, '{}', ?, UTC_TIMESTAMP())")
            ->execute([$projectId, hash('sha256', 'cli-job-' . $projectId)]);

        $environment = [
            'DB_DSN' => (string) getenv('TEST_DB_DSN'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'MEDIA_PRIVATE_ROOT' => $this->mediaRoot,
            'FFPROBE_BINARY' => $this->mediaRoot . '/ffprobe.exe',
        ];
        $baseEnvironment = getenv();
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/process-jobs.php', '--limit=1', '--time-budget=5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(is_array($baseEnvironment) ? $baseEnvironment : [], $environment), ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertJson((string) $stdout, 'Worker output: ' . (string) $stdout . ' stderr: ' . (string) $stderr);
        $summary = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame('', $stderr);
        self::assertSame(1, $summary['claimed']);
        self::assertSame(0, $summary['retried']);
        self::assertSame(1, $summary['deferred']);
        self::assertSame(0, $summary['failed']);
    }

    /** @dataProvider downloadLimits */
    public function testEffectiveLimitIsPassedToFetchHandler(string $phpUploadLimit, string $expectedBytes): void
    {
        $capture = tempnam(sys_get_temp_dir(), 'handler-limit-');
        $prepend = tempnam(sys_get_temp_dir(), 'handler-prepend-');
        self::assertNotFalse($capture);
        self::assertNotFalse($prepend);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($prepend, "<?php\nnamespace { require {$autoload}; }\nnamespace App\\Queue { final class FetchAndProbeHandler implements JobHandler { public function __construct(mixed \$projects, mixed \$sources, mixed \$downloader, mixed \$storage, mixed \$processor, ProcessingEffectGuard \$effects, int \$maxDownloadBytes = 524288000) { file_put_contents((string) getenv('HANDLER_LIMIT_CAPTURE'), (string) \$maxDownloadBytes); } public function handle(ClaimedJob \$job): JobOutcome { return JobOutcome::completed(); } } }\n");
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'DB_DSN' => (string) getenv('TEST_DB_DSN'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'MEDIA_PRIVATE_ROOT' => $this->mediaRoot,
            'MEDIA_MAX_UPLOAD_BYTES' => '10485760',
            'FFPROBE_BINARY' => $this->mediaRoot . '/ffprobe.exe',
            'HANDLER_LIMIT_CAPTURE' => $capture,
        ]);
        try {
            $process = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=' . $phpUploadLimit, '-d', 'post_max_size=32M', '-d', 'auto_prepend_file=' . $prepend, dirname(__DIR__, 2) . '/bin/process-jobs.php', '--limit=1', '--time-budget=5'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            self::assertSame(0, $exit, (string) $stderr . (string) $stdout);
            self::assertSame($expectedBytes, file_get_contents($capture));
        } finally {
            @unlink($prepend);
            @unlink($capture);
        }
    }

    public function downloadLimits(): array
    {
        return ['configured lower' => ['20M', '10485760'], 'PHP lower' => ['2M', '2097152']];
    }
}
