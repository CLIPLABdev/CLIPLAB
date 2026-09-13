<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Services\CreditReservationService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CreditReservationConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $projectId;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable for the multi-process concurrency proof.');
        }
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['concurrent-credit-' . $suffix, 'Concurrent Credit ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 5)'
        )->execute(['Concurrent credit', 'concurrent-credit-' . $suffix . '@example.test', 'x', $this->planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Concurrent credit project', 'ready', 70)"
        )->execute([$this->userId]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO credit_transactions
                (user_id, type, amount, balance_after, reference_type, description)
             VALUES (?, 'credit', 5, 5, 'registration', 'Concurrency fixture')"
        )->execute([$this->userId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->projectId)) {
            $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
        }
        if (isset($this->userId)) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        if (isset($this->planId)) {
            $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
        }
    }

    public function testDistinctKeysCannotOverspendTheSameUserBalance(): void
    {
        $results = $this->runConcurrent([
            ['operation' => 'reserve', 'key' => 'distinct-a', 'units' => 4],
            ['operation' => 'reserve', 'key' => 'distinct-b', 'units' => 4],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(['insufficient', 'ok'], $statuses);
        $insufficient = $results[0]['status'] === 'insufficient' ? $results[0] : $results[1];
        self::assertSame(1, $insufficient['available']);
        self::assertSame(4, $insufficient['required']);
        $this->assertIndependentConnections($results);
        self::assertSame(1, $this->reservationCount());
        self::assertSame(1, $this->reservationLedgerCount());
        self::assertSame(1, $this->latestBalance());
        self::assertSame(1, $this->userMirror());
    }

    public function testSameKeyCreatesExactlyOneReservationAndDebit(): void
    {
        $results = $this->runConcurrent([
            ['operation' => 'reserve', 'key' => 'shared-key', 'units' => 4],
            ['operation' => 'reserve', 'key' => 'shared-key', 'units' => 4],
        ]);

        self::assertSame(['ok', 'ok'], array_column($results, 'status'));
        self::assertSame($results[0]['reservation_id'], $results[1]['reservation_id']);
        $this->assertIndependentConnections($results);
        self::assertSame(1, $this->reservationCount());
        self::assertSame(1, $this->reservationLedgerCount());
        self::assertSame(1, $this->latestBalance());
        self::assertSame(1, $this->userMirror());
    }

    public function testConcurrentRefundCreatesExactlyOneCredit(): void
    {
        $reservation = $this->service()->reserve($this->userId, $this->projectId, 4, 'refund-target');

        $results = $this->runConcurrent([
            ['operation' => 'refund', 'reservation_id' => $reservation->id(), 'reason' => 'analysis_failed'],
            ['operation' => 'refund', 'reservation_id' => $reservation->id(), 'reason' => 'analysis_failed'],
        ]);

        self::assertSame(['ok', 'ok'], array_column($results, 'status'));
        self::assertSame(['refunded', 'refunded'], array_column($results, 'reservation_status'));
        $this->assertIndependentConnections($results);
        self::assertSame(1, $this->reservationCount());
        self::assertSame(2, $this->reservationLedgerCount());
        self::assertSame(5, $this->latestBalance());
        self::assertSame(5, $this->userMirror());
    }

    /**
     * @param list<array<string, int|string>> $commands
     * @return list<array<string, mixed>>
     */
    private function runConcurrent(array $commands): array
    {
        $base = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'credit-concurrency-' . bin2hex(random_bytes(7));
        self::assertTrue(mkdir($directory, 0700, true));
        $goPath = $directory . DIRECTORY_SEPARATOR . 'go';
        $processes = [];
        $paths = [];

        try {
            foreach ($commands as $index => $command) {
                $readyPath = $directory . DIRECTORY_SEPARATOR . 'ready-' . $index;
                $resultPath = $directory . DIRECTORY_SEPARATOR . 'result-' . $index . '.json';
                $workerPath = $directory . DIRECTORY_SEPARATOR . 'worker-' . $index . '.php';
                self::assertNotFalse(file_put_contents(
                    $workerPath,
                    $this->workerScript($base, $readyPath, $goPath, $resultPath, $command)
                ));
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, $workerPath],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                    $base,
                    null,
                    ['bypass_shell' => true]
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'pipes' => $pipes];
                $paths[] = ['ready' => $readyPath, 'result' => $resultPath, 'worker' => $workerPath];
            }

            $this->waitForReadySignals(array_column($paths, 'ready'));
            self::assertNotFalse(file_put_contents($goPath, 'go'));

            $results = [];
            foreach ($processes as $index => &$entry) {
                $stdout = stream_get_contents($entry['pipes'][1]);
                $stderr = stream_get_contents($entry['pipes'][2]);
                fclose($entry['pipes'][1]);
                fclose($entry['pipes'][2]);
                $exitCode = proc_close($entry['process']);
                $entry['process'] = null;
                self::assertSame('', $stdout, 'Concurrent worker wrote unexpected stdout.');
                self::assertSame('', $stderr, 'Concurrent worker wrote unexpected stderr.');
                self::assertSame(0, $exitCode, 'Concurrent worker failed.');
                self::assertFileExists($paths[$index]['result']);
                $decoded = json_decode(
                    (string) file_get_contents($paths[$index]['result']),
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                );
                self::assertIsArray($decoded);
                if (($decoded['status'] ?? null) === 'error') {
                    self::fail('Concurrent worker error: ' . ($decoded['class'] ?? 'unknown'));
                }
                $results[] = $decoded;
            }
            unset($entry);

            return $results;
        } finally {
            foreach ($processes as $entry) {
                if (is_resource($entry['process'])) {
                    proc_terminate($entry['process']);
                    foreach ($entry['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($entry['process']);
                }
            }
            foreach ($paths as $pathSet) {
                @unlink($pathSet['ready']);
                @unlink($pathSet['result']);
                @unlink($pathSet['worker']);
            }
            @unlink($goPath);
            @rmdir($directory);
        }
    }

    /** @param array<string, int|string> $command */
    private function workerScript(
        string $base,
        string $readyPath,
        string $goPath,
        string $resultPath,
        array $command
    ): string {
        $dsn = (string) getenv('TEST_DB_DSN');
        $username = getenv('TEST_DB_USERNAME') ?: null;
        $password = getenv('TEST_DB_PASSWORD') ?: null;
        $operation = (string) ($command['operation'] ?? '');
        if ($operation === 'reserve') {
            $call = '$reservation = $service->reserve(' . $this->userId . ', ' . $this->projectId . ', '
                . (int) $command['units'] . ', ' . var_export((string) $command['key'], true) . ');' . "\n"
                . '$payload = [\'status\' => \'ok\', \'reservation_id\' => $reservation->id(), '
                . "'reservation_status' => \$reservation->status(), 'connection_id' => \$connectionId];";
        } elseif ($operation === 'refund') {
            $call = '$reservation = $service->refund(' . (int) $command['reservation_id'] . ', '
                . var_export((string) $command['reason'], true) . ');' . "\n"
                . '$payload = [\'status\' => \'ok\', \'reservation_id\' => $reservation->id(), '
                . "'reservation_status' => \$reservation->status(), 'connection_id' => \$connectionId];";
        } else {
            self::fail('Unsupported concurrency command.');
        }

        return "<?php\ndeclare(strict_types=1);\n"
            . 'require ' . var_export($base . '/vendor/autoload.php', true) . ";\n"
            . '$pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($username, true) . ', '
            . var_export($password, true) . ', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, '
            . 'PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);' . "\n"
            . "\$pdo->exec(\"SET time_zone = '+00:00'\");\n"
            . '$connectionId = (int) $pdo->query(\'SELECT CONNECTION_ID()\')->fetchColumn();' . "\n"
            . '$service = new App\\Services\\CreditReservationService($pdo, '
            . 'new App\\Repositories\\CreditReservationRepository($pdo), '
            . 'new App\\Repositories\\CreditTransactionRepository($pdo), 1);' . "\n"
            . 'file_put_contents(' . var_export($readyPath, true) . ", 'ready');\n"
            . '$deadline = microtime(true) + 10;' . "\n"
            . 'while (!is_file(' . var_export($goPath, true) . ')) {' . "\n"
            . "    if (microtime(true) >= \$deadline) { exit(70); }\n"
            . "    usleep(10000);\n}\n"
            . "try {\n    " . str_replace("\n", "\n    ", $call) . "\n"
            . '} catch (App\\Credits\\InsufficientCredits $exception) {' . "\n"
            . "    \$payload = ['status' => 'insufficient', 'available' => \$exception->available(), "
            . "'required' => \$exception->required(), 'connection_id' => \$connectionId];\n"
            . '} catch (Throwable $exception) {' . "\n"
            . "    \$payload = ['status' => 'error', 'class' => get_class(\$exception), "
            . "'connection_id' => \$connectionId];\n}\n"
            . 'file_put_contents(' . var_export($resultPath, true)
            . ', json_encode($payload, JSON_THROW_ON_ERROR));' . "\n";
    }

    /** @param list<string> $readyPaths */
    private function waitForReadySignals(array $readyPaths): void
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            if (count(array_filter($readyPaths, 'is_file')) === count($readyPaths)) {
                return;
            }
            usleep(10000);
        }
        self::fail('Concurrent workers did not reach the rendezvous barrier.');
    }

    /** @param list<array<string, mixed>> $results */
    private function assertIndependentConnections(array $results): void
    {
        self::assertCount(2, $results);
        self::assertNotSame($results[0]['connection_id'], $results[1]['connection_id']);
    }

    private function service(): CreditReservationService
    {
        return new CreditReservationService(
            $this->pdo,
            new CreditReservationRepository($this->pdo),
            new CreditTransactionRepository($this->pdo),
            1
        );
    }

    private function reservationCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM credit_reservations WHERE user_id = ?');
        $statement->execute([$this->userId]);

        return (int) $statement->fetchColumn();
    }

    private function reservationLedgerCount(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM credit_transactions
             WHERE user_id = ? AND reference_type = 'credit_reservation'"
        );
        $statement->execute([$this->userId]);

        return (int) $statement->fetchColumn();
    }

    private function latestBalance(): int
    {
        return (new CreditTransactionRepository($this->pdo))->latestBalanceForUser($this->userId);
    }

    private function userMirror(): int
    {
        $statement = $this->pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $statement->execute([$this->userId]);

        return (int) $statement->fetchColumn();
    }
}
