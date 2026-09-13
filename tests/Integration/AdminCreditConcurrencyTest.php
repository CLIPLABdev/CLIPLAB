<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\AdminRepository;
use App\Repositories\SystemLogRepository;
use App\Services\AdminService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class AdminCreditConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $adminId;
    private int $userId;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable for the concurrency proof.');
        }
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $suffix = bin2hex(random_bytes(7));
        $features = '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":104857600,"storage_bytes":1073741824}}';
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, ?)')->execute(['admin-concurrency-' . $suffix, 'Admin concurrency ' . $suffix, $features]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $insert = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits, role) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute(['Admin concurrency', 'admin-' . $suffix . '@example.test', 'x', $this->planId, 0, 'admin']);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $insert->execute(['Target concurrency', 'target-' . $suffix . '@example.test', 'x', $this->planId, 5, 'user']);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (?, 'credit', 5, 5, 'fixture', 'Concurrency fixture')")->execute([$this->userId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) { return; }
        $this->pdo->prepare('DELETE FROM system_logs WHERE actor_id = ? OR (target_type = ? AND target_id = ?)')->execute([$this->adminId, 'user', $this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$this->adminId, $this->userId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testTwoConcurrentDebitsCannotCreateNegativeBalanceOrDuplicateSuccess(): void
    {
        $directory = sys_get_temp_dir() . '/admin-credit-' . bin2hex(random_bytes(7));
        self::assertTrue(mkdir($directory, 0700, true));
        $go = $directory . '/go';
        $processes = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $worker = $directory . '/worker-' . $index . '.php';
                $result = $directory . '/result-' . $index . '.json';
                $ready = $directory . '/ready-' . $index;
                self::assertNotFalse(file_put_contents($worker, $this->workerScript($ready, $go, $result)));
                $pipes = [];
                $process = proc_open([PHP_BINARY, $worker], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = compact('process', 'pipes', 'worker', 'result', 'ready');
            }
            $deadline = microtime(true) + 10;
            while ((!is_file($processes[0]['ready']) || !is_file($processes[1]['ready'])) && microtime(true) < $deadline) { usleep(10000); }
            self::assertFileExists($processes[0]['ready']);
            self::assertFileExists($processes[1]['ready']);
            self::assertNotFalse(file_put_contents($go, 'go'));
            $outcomes = [];
            foreach ($processes as &$entry) {
                $stdout = stream_get_contents($entry['pipes'][1]);
                $stderr = stream_get_contents($entry['pipes'][2]);
                fclose($entry['pipes'][1]); fclose($entry['pipes'][2]);
                self::assertSame(0, proc_close($entry['process']), $stdout . $stderr);
                $entry['process'] = null;
                $outcomes[] = json_decode((string) file_get_contents($entry['result']), true, 16, JSON_THROW_ON_ERROR);
            }
            unset($entry);
            $statuses = array_column($outcomes, 'status'); sort($statuses);
            self::assertSame(['invalid', 'ok'], $statuses);
            self::assertSame(1, (int) $this->pdo->query('SELECT credits FROM users WHERE id = ' . $this->userId)->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM credit_transactions WHERE user_id = {$this->userId} AND reference_type = 'admin_adjustment'")->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM system_logs WHERE target_type = 'user' AND target_id = {$this->userId} AND event_code = 'admin.credits_adjusted'")->fetchColumn());
        } finally {
            foreach ($processes as $entry) {
                if (is_resource($entry['process'])) { proc_terminate($entry['process']); proc_close($entry['process']); }
                @unlink($entry['worker']); @unlink($entry['result']); @unlink($entry['ready']);
            }
            @unlink($go); @rmdir($directory);
        }
    }

    private function workerScript(string $ready, string $go, string $result): string
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $dsn = var_export((string) getenv('TEST_DB_DSN'), true);
        $username = var_export(getenv('TEST_DB_USERNAME') ?: null, true);
        $password = var_export(getenv('TEST_DB_PASSWORD') ?: null, true);
        return "<?php\ndeclare(strict_types=1);\nrequire {$autoload};\n"
            . "\$pdo=new PDO({$dsn},{$username},{$password},[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);\n"
            . "\$repository=new App\\Repositories\\AdminRepository(\$pdo);\n\$logs=new App\\Repositories\\SystemLogRepository(\$pdo);\n\$service=new App\\Services\\AdminService(\$pdo,\$repository,\$logs);\n"
            . 'file_put_contents(' . var_export($ready, true) . ",'ready');\nwhile(!is_file(" . var_export($go, true) . ")){usleep(10000);}\n"
            . "try{\$balance=\$service->adjustCredits({$this->adminId},{$this->userId},-4,'Concorrência aprovada');\$out=['status'=>'ok','balance'=>\$balance];}catch(DomainException \$e){\$out=['status'=>'invalid'];}catch(Throwable \$e){\$out=['status'=>'error','class'=>get_class(\$e)];}\n"
            . 'file_put_contents(' . var_export($result, true) . ",json_encode(\$out,JSON_THROW_ON_ERROR));\n";
    }
}
