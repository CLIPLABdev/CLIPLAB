<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class ProcessJobsCommandHardeningTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        foreach (['APP_ENV_FILE', 'DB_DSN', 'DB_USERNAME', 'DB_PASSWORD', 'TEST_DB_DSN', 'TEST_DB_USERNAME', 'TEST_DB_PASSWORD', 'PROCESS_JOBS_BOOTSTRAP_PATH'] as $name) {
            $this->environment[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DELETE FROM processing_jobs');
        }
        foreach ($this->environment as $name => $value) {
            putenv($name . ($value === false ? '' : '=' . $value));
        }
    }
    public function testAcceptsDefaultsAndNamedArgumentsInAnyOrder(): void
    {
        $this->prepareDatabase();
        $defaults = $this->runCommand();
        $reordered = $this->runCommand('--time-budget=5', '--queue=media', '--limit=1');
        $partial = $this->runCommand('--limit=1');

        self::assertSame(0, $defaults['exit']);
        self::assertSame(0, $reordered['exit']);
        self::assertSame(0, $partial['exit']);
    }

    public function testBootstrapFailureReturnsOnlySanitizedJsonOnStderr(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'queue-bootstrap-');
        self::assertNotFalse($fixture);
        file_put_contents($fixture, "<?php throw new RuntimeException('C:/private/secret/trace');");
        putenv('PROCESS_JOBS_BOOTSTRAP_PATH=' . $fixture);

        try {
            $result = $this->runCommand('--limit=1');
        } finally {
            unlink($fixture);
        }

        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertSame('{"error":"worker_bootstrap_failed"}' . PHP_EOL, $result['stderr']);
    }

    public function testLeaseFailureUsesTheSharedBudgetBeforeOpeningTheDatabase(): void
    {
        $prepend = tempnam(sys_get_temp_dir(), 'queue-database-marker-');
        $marker = tempnam(sys_get_temp_dir(), 'queue-database-effect-');
        self::assertNotFalse($prepend);
        self::assertNotFalse($marker);
        unlink($marker);
        file_put_contents($prepend, <<<'PHP'
<?php
namespace App\Core {
    final class Database {
        public static function connection(): \PDO {
            file_put_contents((string) getenv('DATABASE_EFFECT_MARKER'), 'opened');
            throw new \RuntimeException('database-opened');
        }
    }
}
PHP
        );

        try {
            $environment = getenv();
            $environment = array_merge(is_array($environment) ? $environment : [], [
                'APP_ENV_FILE' => sys_get_temp_dir() . '/cliplab-no-env-file',
                'DATABASE_EFFECT_MARKER' => $marker,
                'GEMINI_HTTP_TIMEOUT_SECONDS' => '10',
                'RENDER_TIMEOUT_SECONDS' => '5',
                'MEDIA_DOWNLOAD_TIMEOUT_SECONDS' => '120',
                'PROCESS_TIMEOUT_SECONDS' => '60',
                'QUEUE_LEASE_SECONDS' => '209',
            ]);
            $process = proc_open(
                [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, dirname(__DIR__, 2) . '/bin/process-jobs.php', '--limit=1'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
                ['bypass_shell' => true]
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(1, proc_close($process));
            self::assertSame('', (string) $stdout);
            self::assertSame("Falha ao iniciar o worker.\n", (string) $stderr);
            self::assertFileDoesNotExist($marker);
        } finally {
            @unlink($prepend);
            @unlink($marker);
        }

        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/process-jobs.php');
        self::assertStringContainsString('WorkerLeaseBudget::requiredSeconds(', $source);
    }

    private function prepareDatabase(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $username = getenv('TEST_DB_USERNAME');
        $password = getenv('TEST_DB_PASSWORD');
        putenv('DB_DSN=' . $dsn);
        putenv('DB_USERNAME=' . (is_string($username) ? $username : ''));
        putenv('DB_PASSWORD=' . (is_string($password) ? $password : ''));
        $this->pdo = SafePhase5TestDatabase::using(
            $dsn,
            static fn (string $safeDsn): PDO => new PDO(
                $safeDsn,
                is_string($username) && $username !== '' ? $username : null,
                is_string($password) && $password !== '' ? $password : null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            )
        );
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->pdo->exec('DELETE FROM processing_jobs');
    }
    /** @return array{exit:int, stdout:string, stderr:string} */
    private function runCommand(string ...$arguments): array
    {
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'APP_ENV_FILE' => sys_get_temp_dir() . '/cliplab-no-env-file',
        ]);
        $process = proc_open(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/process-jobs.php'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }
}
