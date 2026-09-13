<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\ProjectRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectIntakeConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable for the multi-process concurrency proof.');
        }
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Concurrent intake', 'concurrent-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->pdo, $this->userId)) {
            $statement = $this->pdo->prepare('DELETE FROM users WHERE id = ?');
            $statement->execute([$this->userId]);
        }
    }

    public function testConcurrentIndependentPdosSerializeOneIdempotentProject(): void
    {
        $base = dirname(__DIR__, 2);
        $key = hash('sha256', $this->userId . ':concurrent-key');
        $temporaryDirectory = sys_get_temp_dir() . '/intake-concurrency-' . bin2hex(random_bytes(6));
        mkdir($temporaryDirectory, 0700, true);
        $readyPath = $temporaryDirectory . '/ready';
        $resultPath = $temporaryDirectory . '/result.json';
        $workerPath = $temporaryDirectory . '/worker.php';
        file_put_contents($workerPath, $this->workerScript($base, $readyPath, $resultPath, $key));

        $process = null;
        $pipes = [];
        try {
            $this->pdo->beginTransaction();
            $first = (new ProjectRepository($this->pdo))->createOrFindForIntake($this->userId, 'Concurrent intake', $key, 'source.mp4');
            $process = proc_open([PHP_BINARY, $workerPath], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $base, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $this->waitFor($readyPath);
            usleep(150000);
            $state = proc_get_status($process);
            self::assertTrue($state['running'], 'The second PDO must still be waiting on the uncommitted unique key.');
            $this->pdo->commit();
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $process = null;

            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
            self::assertSame(0, $exitCode);
            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($first['id'], $result['id']);
            self::assertFalse($result['created']);
            self::assertSame(1, $this->projectCount($key));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            @unlink($workerPath);
            @unlink($readyPath);
            @unlink($resultPath);
            @rmdir($temporaryDirectory);
        }
    }

    private function workerScript(string $base, string $readyPath, string $resultPath, string $key): string
    {
        $dsn = (string) getenv('TEST_DB_DSN');
        $username = getenv('TEST_DB_USERNAME') ?: null;
        $password = getenv('TEST_DB_PASSWORD') ?: null;
        return "<?php\ndeclare(strict_types=1);\nrequire " . var_export($base . '/vendor/autoload.php', true) . ";\n"
            . 'file_put_contents(' . var_export($readyPath, true) . ", 'ready');\n"
            . "\$pdo = new PDO(" . var_export($dsn, true) . ', ' . var_export($username, true) . ', ' . var_export($password, true) . ', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);' . "\n"
            . "\$result = (new App\\Repositories\\ProjectRepository(\$pdo))->createOrFindForIntake(" . $this->userId . ", 'Concurrent intake', " . var_export($key, true) . ", 'source.mp4');\n"
            . 'file_put_contents(' . var_export($resultPath, true) . ', json_encode($result, JSON_THROW_ON_ERROR));' . "\n";
    }

    private function waitFor(string $path): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (is_file($path)) {
                return;
            }
            usleep(20000);
        }
        self::fail('The competing PDO worker did not start.');
    }

    private function projectCount(string $key): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM projects WHERE user_id = ? AND ingest_key = ?');
        $statement->execute([$this->userId, $key]);
        return (int) $statement->fetchColumn();
    }
}
