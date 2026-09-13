<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Migrator;

use PDO;

use PHPUnit\Framework\TestCase;

final class ProcessJobsCommandTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];
    private ?PDO $pdo = null;
    private ?int $insertedUserId = null;
    private ?int $insertedPlanId = null;

    protected function setUp(): void
    {
        foreach (['DB_DSN', 'DB_USERNAME', 'DB_PASSWORD', 'TEST_DB_DSN', 'TEST_DB_USERNAME', 'TEST_DB_PASSWORD'] as $name) {
            $this->environment[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS process_jobs_command_transition_failure');
            $this->pdo->exec('DELETE FROM processing_jobs');
            if ($this->insertedUserId !== null) {
                $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->insertedUserId]);
            }
            if ($this->insertedPlanId !== null) {
                $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->insertedPlanId]);
            }
        }
        foreach ($this->environment as $name => $value) {
            putenv($name . ($value === false ? '' : '=' . $value));
        }
    }
    public function testRejectsUnsupportedOrOutOfRangeOptions(): void
    {
        $result = $this->runCommand('--queue=other', '--limit=11', '--time-budget=4');

        self::assertSame(2, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('Uso:', $result['stderr']);
    }

    public function testPrintsOneSafeJsonSummaryForAValidFiniteInvocation(): void
    {
        $this->prepareDatabase();
        $result = $this->runCommand('--queue=media', '--limit=1', '--time-budget=5');

        self::assertSame(0, $result['exit']);
        self::assertSame('', $result['stderr']);
        $summary = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['claimed', 'completed', 'retried', 'failed', 'operational_errors'], array_keys($summary));
        self::assertSame(0, $summary['operational_errors']);
        self::assertArrayNotHasKey('payload', $summary);
        self::assertArrayNotHasKey('lease_token', $summary);
    }

    public function testReturnsNonzeroAndASecretFreeOperationalErrorCountWhenATransitionFails(): void
    {
        $this->prepareDatabase();
        $this->insertQueuedJob();
        $this->pdo->exec(<<<'SQL'
CREATE TRIGGER process_jobs_command_transition_failure
BEFORE UPDATE ON processing_jobs
FOR EACH ROW
BEGIN
    IF NEW.status = 'failed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'C:/private/worker-secret';
    END IF;
END
SQL);

        $result = $this->runCommand('--queue=media', '--limit=1', '--time-budget=5');

        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stderr']);
        $summary = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $summary['claimed']);
        self::assertSame(1, $summary['operational_errors']);
        self::assertStringNotContainsString('C:/private/worker-secret', $result['stdout']);
    }

    private function prepareDatabase(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $username = getenv('TEST_DB_USERNAME');
        $password = getenv('TEST_DB_PASSWORD');
        putenv('DB_DSN=' . $dsn);
        putenv('DB_USERNAME=' . (is_string($username) ? $username : ''));
        putenv('DB_PASSWORD=' . (is_string($password) ? $password : ''));
        $this->pdo = new PDO($dsn, is_string($username) && $username !== '' ? $username : null, is_string($password) && $password !== '' ? $password : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->pdo->exec('DELETE FROM processing_jobs');
    }
    private function insertQueuedJob(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['process-jobs-' . $suffix, 'Process jobs ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->insertedPlanId = $planId;
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')
            ->execute(['Process jobs ' . $suffix, $suffix . '@example.test', 'not-a-real-hash', $planId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->insertedUserId = $userId;
        $this->pdo->prepare('INSERT INTO projects (user_id, name, status) VALUES (?, ?, ?)')
            ->execute([$userId, 'Process jobs project', 'queued']);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO processing_jobs (queue_name, type, project_id, payload_json, idempotency_key, available_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute(['media', 'probe_source', $projectId, '{}', hash('sha256', $suffix)]);
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    private function runCommand(string ...$arguments): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/process-jobs.php'], $arguments);
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }
}
