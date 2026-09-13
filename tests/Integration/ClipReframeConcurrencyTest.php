<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ProjectRepository;
use App\Services\ClipRenderRequestService;
use App\Services\DatabaseJobDispatcher;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class ClipReframeConcurrencyTest extends TestCase
{
    private PDO $pdo;
    private string $dsn;
    private int $planId;
    private int $userId;
    private int $projectId;
    private int $clipId;

    protected function setUp(): void
    {
        $this->pdo = SafePhase5TestDatabase::using(
            getenv('TEST_DB_DSN'),
            function (string $dsn): PDO {
                $this->dsn = $dsn;

                return $this->connection();
            }
        );
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['reframe-race-' . $suffix, 'Reframe race ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 10)'
        )->execute(['Reframe race', 'reframe-race-' . $suffix . '@example.test', 'not-a-real-hash', $this->planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description)
             VALUES (?, 'credit', 10, 10, 'test_fixture', 'Reframe race credits')"
        )->execute([$this->userId]);
        $this->pdo->prepare(
            "INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Reframe race', 'suggestions_ready', 92)"
        )->execute([$this->userId]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO project_sources
                (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes,
                 duration_seconds, width, height, video_codec, has_audio, status)
             VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 240, 1920, 1080, 'h264', 1, 'ready')"
        )->execute([$this->projectId, 'imports/' . $this->projectId . '/source.mp4']);
        $this->pdo->prepare(
            "INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json)
             VALUES (?, 'reframe-race', 'test-model', 'completed', JSON_OBJECT('clips', JSON_ARRAY()))"
        )->execute([$this->projectId]);
        $analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO clips
                (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time,
                 duration_seconds, viral_score, hook, reason, category)
             VALUES (?, ?, 0, 'Reframe race clip', 10, 40, 30, 90, 'Hook', 'Reason', 'insight')"
        )->execute([$this->projectId, $analysisId]);
        $this->clipId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testCompetingPlanWaitsForTheLockAndCannotReplaceTheCommittedSnapshot(): void
    {
        self::assertTrue(function_exists('proc_open'), 'proc_open is required for the concurrency proof.');
        $ledgerBefore = $this->ledgerCount();
        $base = dirname(__DIR__, 2);
        $temporaryDirectory = sys_get_temp_dir() . '/clip-reframe-race-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($temporaryDirectory, 0700, true));
        $readyPath = $temporaryDirectory . '/ready';
        $attemptPath = $temporaryDirectory . '/attempt';
        $resultPath = $temporaryDirectory . '/result.json';
        $workerPath = $temporaryDirectory . '/worker.php';
        self::assertNotFalse(file_put_contents(
            $workerPath,
            $this->workerScript($base, $readyPath, $attemptPath, $resultPath)
        ));
        $process = null;
        $pipes = [];

        try {
            $this->pdo->beginTransaction();
            $created = $this->service($this->pdo)->request(
                $this->clipId,
                $this->userId,
                '12.500',
                '14.500',
                new ReframeSubmission('9:16', 'manual', '0.250000', '0.500000', '')
            );
            self::assertNotNull($created);
            self::assertTrue($created->created());
            self::assertSame(1, $created->revision());

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
            $connectionId = (int) $this->waitForFile($readyPath, 10.0);
            self::assertGreaterThan(0, $connectionId);
            self::assertSame('request', $this->waitForFile($attemptPath, 10.0));
            self::assertStringContainsString('FOR UPDATE', strtoupper($this->waitForLockWait($connectionId, 10.0)));
            self::assertFileDoesNotExist($resultPath);

            $this->pdo->commit();
            $exitCode = $this->waitForProcessExit($process, 10.0);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $process = null;

            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
            self::assertSame(0, $exitCode);
            self::assertSame([
                'clip_id' => $this->clipId,
                'project_id' => $this->projectId,
                'revision' => 1,
                'created' => false,
            ], json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
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
            @unlink($attemptPath);
            @unlink($resultPath);
            @rmdir($temporaryDirectory);
        }

        $profiles = new ClipRenderProfileRepository($this->pdo);
        $plan = $profiles->findForClipRevision($this->clipId, 1);
        self::assertNotNull($plan);
        self::assertSame('manual', $plan->mode());
        self::assertSame('9:16', $plan->aspectRatio()->value());
        self::assertSame('0.250000', $plan->keyframes()[0]->centerXDecimal());
        self::assertSame(1, $this->countForClip('clip_render_profiles'));
        self::assertSame(1, $this->keyframeCount());
        self::assertSame(1, $this->countForClip('processing_jobs'));
        $job = $this->pdo->prepare('SELECT payload_json, idempotency_key FROM processing_jobs WHERE project_id = ?');
        $job->execute([$this->projectId]);
        $row = $job->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(
            ['clip_id' => $this->clipId, 'render_revision' => 1],
            json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame(hash('sha256', 'clip-render:' . $this->clipId . ':v1'), $row['idempotency_key']);
        self::assertSame($ledgerBefore, $this->ledgerCount());
    }

    private function service(PDO $pdo): ClipRenderRequestService
    {
        return new ClipRenderRequestService(
            $pdo,
            new ClipRepository($pdo),
            new ProjectRepository($pdo),
            new ClipRenderProfileRepository($pdo),
            new ReframePlanValidator(),
            new DatabaseJobDispatcher($pdo)
        );
    }

    private function connection(): PDO
    {
        return new PDO($this->dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function workerScript(string $base, string $readyPath, string $attemptPath, string $resultPath): string
    {
        return "<?php\ndeclare(strict_types=1);\nrequire " . var_export($base . '/vendor/autoload.php', true) . ";\n"
            . "\$pdo = new PDO(" . var_export($this->dsn, true) . ", getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);\n"
            . "\$pdo->exec('SET SESSION innodb_lock_wait_timeout = 15');\n"
            . 'file_put_contents(' . var_export($readyPath, true) . ", (string) \$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());\n"
            . "\$service = new App\\Services\\ClipRenderRequestService(\$pdo, new App\\Repositories\\ClipRepository(\$pdo), new App\\Repositories\\ProjectRepository(\$pdo), new App\\Repositories\\ClipRenderProfileRepository(\$pdo), new App\\Media\\Reframe\\ReframePlanValidator(), new App\\Services\\DatabaseJobDispatcher(\$pdo));\n"
            . 'file_put_contents(' . var_export($attemptPath, true) . ", 'request');\n"
            . "\$receipt = \$service->request({$this->clipId}, {$this->userId}, '12.500', '14.500', new App\\Media\\Reframe\\ReframeSubmission('invalid', 'invalid', '', '', '', true));\n"
            . 'file_put_contents(' . var_export($resultPath, true) . ", json_encode(['clip_id' => \$receipt?->clipId(), 'project_id' => \$receipt?->projectId(), 'revision' => \$receipt?->revision(), 'created' => \$receipt?->created()], JSON_THROW_ON_ERROR));\n";
    }

    private function waitForFile(string $path, float $timeoutSeconds): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for the competing PDO process to start.');
    }

    private function waitForLockWait(int $connectionId, float $timeoutSeconds): string
    {
        $statement = $this->pdo->prepare(
            'SELECT trx_state, trx_query FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?'
        );
        $lastTransaction = null;
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $statement->execute([$connectionId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            $lastTransaction = is_array($row) ? $row : null;
            if (is_array($row) && strtoupper((string) $row['trx_state']) === 'LOCK WAIT') {
                return (string) $row['trx_query'];
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        $process = $this->pdo->prepare(
            'SELECT COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID = ?'
        );
        $process->execute([$connectionId]);
        $processRow = $process->fetch(PDO::FETCH_ASSOC);
        $lastProcess = is_array($processRow) ? $processRow : null;

        self::fail(
            'The competing request did not enter an InnoDB lock wait. Last transaction: '
            . json_encode($lastTransaction) . '; last process: ' . json_encode($lastProcess)
        );
    }

    /** @param resource $process */
    private function waitForProcessExit($process, float $timeoutSeconds): int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return (int) $status['exitcode'];
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        self::fail('The competing PDO process did not finish after the row lock was released.');
    }

    private function countForClip(string $table): int
    {
        if ($table === 'clip_render_profiles') {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM clip_render_profiles WHERE clip_id = ?');
            $statement->execute([$this->clipId]);
        } else {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM processing_jobs WHERE project_id = ?');
            $statement->execute([$this->projectId]);
        }

        return (int) $statement->fetchColumn();
    }

    private function keyframeCount(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM clip_reframe_keyframes k INNER JOIN clip_render_profiles p '
            . 'ON p.id = k.render_profile_id WHERE p.clip_id = ?'
        );
        $statement->execute([$this->clipId]);

        return (int) $statement->fetchColumn();
    }

    private function ledgerCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?');
        $statement->execute([$this->userId]);

        return (int) $statement->fetchColumn();
    }
}
