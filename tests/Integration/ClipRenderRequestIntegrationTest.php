<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\JobDispatcher;
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
use RuntimeException;
use Tests\Support\SafePhase5TestDatabase;

final class ClipRenderRequestIntegrationTest extends TestCase
{
    private PDO $pdo;
    private string $dsn;
    private int $planId;
    private int $ownerId;
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
            ->execute(['clip-request-' . $suffix, 'Clip request ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 10)')
            ->execute(['Render owner', 'render-owner-' . $suffix . '@example.test', 'not-a-real-hash', $this->planId]);
        $this->ownerId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description)
             VALUES (?, 'credit', 10, 10, 'registration', 'Initial credits')"
        )->execute([$this->ownerId]);
        $this->pdo->prepare(
            "INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Render request fixture', 'suggestions_ready', 92)"
        )->execute([$this->ownerId]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO project_sources
                (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes,
                 duration_seconds, width, height, video_codec, has_audio, status)
             VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 240, 1920, 1080, 'h264', 1, 'ready')"
        )->execute([$this->projectId, 'imports/' . $this->projectId . '/source.mp4']);
        $this->pdo->prepare('UPDATE project_sources SET sha256=? WHERE project_id=?')->execute([str_repeat('a',64),$this->projectId]);
        $this->pdo->prepare(
            "INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json)
             VALUES (?, 'render-request-test', 'test-model', 'completed', JSON_OBJECT('clips', JSON_ARRAY()))"
        )->execute([$this->projectId]);
        $analysisId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO clips
                (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time,
                 duration_seconds, viral_score, hook, reason, category)
             VALUES (?, ?, 0, 'Render request clip', 10, 40, 30, 90, 'Hook', 'Reason', 'insight')"
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
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->ownerId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testConcurrentRequestsWaitForTheRowLockAndCreateExactlyOneRevisionAndJob(): void
    {
        self::assertTrue(function_exists('proc_open'), 'proc_open is required for the concurrency proof.');
        $ledgerBefore = $this->ledgerCount();
        $base = dirname(__DIR__, 2);
        $temporaryDirectory = sys_get_temp_dir() . '/clip-render-lock-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($temporaryDirectory, 0700, true));
        $readyPath = $temporaryDirectory . '/ready';
        $attemptPath = $temporaryDirectory . '/attempt';
        $resultPath = $temporaryDirectory . '/result.json';
        $workerPath = $temporaryDirectory . '/worker.php';
        self::assertNotFalse(file_put_contents(
            $workerPath,
            $this->concurrentWorkerScript($base, $readyPath, $attemptPath, $resultPath)
        ));
        $process = null;
        $pipes = [];

        try {
            $prepared=$this->sourceDurations($this->pdo)->prepare($this->projectId,$this->ownerId);
            $this->pdo->beginTransaction();
            $created = $this->service($this->pdo, new DatabaseJobDispatcher($this->pdo))
                ->request($this->clipId, $this->ownerId, '12.500', '35.250',null,$prepared);
            self::assertNotNull($created);
            self::assertTrue($created->created());
            self::assertSame(1, $created->revision());
            self::assertTrue($this->pdo->inTransaction());

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

            $blockedQuery = $this->waitForLockWait($connectionId, 10.0);
            self::assertStringContainsString('FOR UPDATE', strtoupper($blockedQuery));
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
            $replayed = json_decode(
                (string) file_get_contents($resultPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame($this->clipId, $replayed['clip_id']);
            self::assertSame($this->projectId, $replayed['project_id']);
            self::assertSame(1, $replayed['revision']);
            self::assertFalse($replayed['created']);
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

        $clip = $this->pdo->query(
            'SELECT status, render_start_time, render_end_time, render_revision FROM clips WHERE id = ' . $this->clipId
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('queued', $clip['status']);
        self::assertSame('12.500', $clip['render_start_time']);
        self::assertSame('35.250', $clip['render_end_time']);
        self::assertSame(1, (int) $clip['render_revision']);

        $statement = $this->pdo->prepare(
            'SELECT type, project_id, payload_json, idempotency_key FROM processing_jobs WHERE project_id = ?'
        );
        $statement->execute([$this->projectId]);
        $jobs = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $jobs);
        self::assertSame('render_clip', $jobs[0]['type']);
        self::assertSame($this->projectId, (int) $jobs[0]['project_id']);
        self::assertSame(
            ['clip_id' => $this->clipId, 'render_revision' => 1],
            json_decode((string) $jobs[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame(hash('sha256', 'clip-render:' . $this->clipId . ':v1'), $jobs[0]['idempotency_key']);
        self::assertSame(1, $this->profileCount(1));
        self::assertSame(0, $this->keyframeCount(1));
        self::assertSame(['rendering', '96'], $this->projectState());
        self::assertSame($ledgerBefore, $this->ledgerCount());
    }

    public function testDispatcherFailureRollsBackToSavepointAndLeavesCallerTransactionOpen(): void
    {
        $ledgerBefore = $this->ledgerCount();
        $before = $this->clipState();
        $dispatcher = new class implements JobDispatcher {
            public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
            {
                throw new RuntimeException('forced external transaction dispatch failure');
            }
        };

        $prepared=$this->sourceDurations($this->pdo)->prepare($this->projectId,$this->ownerId);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE projects SET name = 'caller marker' WHERE id = ?")
                ->execute([$this->projectId]);
            try {
                $this->service($this->pdo, $dispatcher)
                    ->request(
                        $this->clipId,
                        $this->ownerId,
                        '12.500',
                        '35.250',
                        new ReframeSubmission('9:16', 'manual', '0.250000', '0.500000', ''),
                        $prepared
                    );
                self::fail('The forced dispatch failure was swallowed.');
            } catch (RuntimeException $exception) {
                self::assertSame('forced external transaction dispatch failure', $exception->getMessage());
            }

            self::assertTrue($this->pdo->inTransaction());
            self::assertSame($before, $this->clipState());
            self::assertSame(['suggestions_ready', '92'], $this->projectState());
            self::assertSame(0, $this->jobCount());
            self::assertSame(0, $this->profileCount(1));
            self::assertSame(0, $this->keyframeCount(1));
            self::assertSame($ledgerBefore, $this->ledgerCount());
            self::assertSame(
                'caller marker',
                $this->pdo->query('SELECT name FROM projects WHERE id = ' . $this->projectId)->fetchColumn()
            );

            $this->pdo->commit();
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        $observer = $this->connection();
        self::assertSame(
            'caller marker',
            $observer->query('SELECT name FROM projects WHERE id = ' . $this->projectId)->fetchColumn()
        );
    }

    public function testDispatcherExceptionRollsBackTheEntireRequestAndLeavesLedgerUntouched(): void
    {
        $ledgerBefore = $this->ledgerCount();
        $before = $this->clipState();
        $dispatcher = new class implements JobDispatcher {
            public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
            {
                throw new RuntimeException('forced dispatch failure');
            }
        };

        try {
            $this->service($this->pdo, $dispatcher)
                ->request(
                    $this->clipId,
                    $this->ownerId,
                    '12.500',
                    '35.250',
                    new ReframeSubmission('9:16', 'manual', '0.250000', '0.500000', '')
                );
            self::fail('The forced dispatch failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced dispatch failure', $exception->getMessage());
        }

        self::assertSame($before, $this->clipState());
        self::assertSame(['suggestions_ready', '92'], $this->projectState());
        self::assertSame(0, $this->jobCount());
        self::assertSame(0, $this->profileCount(1));
        self::assertSame(0, $this->keyframeCount(1));
        self::assertSame($ledgerBefore, $this->ledgerCount());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testFailedRetryCreatesTheNextImmutableSnapshotAndJobWithoutChangingLedger(): void
    {
        $ledgerBefore = $this->ledgerCount();
        $profiles = new ClipRenderProfileRepository($this->pdo);

        $first = $this->service($this->pdo, new DatabaseJobDispatcher($this->pdo))->request(
            $this->clipId,
            $this->ownerId,
            '12.500',
            '14.500',
            new ReframeSubmission('9:16', 'manual', '0.250000', '0.500000', '')
        );
        self::assertNotNull($first);
        self::assertSame(1, $first->revision());
        $this->pdo->prepare("UPDATE clips SET status = 'failed', render_error_code = 'render_failed' WHERE id = ?")
            ->execute([$this->clipId]);

        $second = $this->service($this->pdo, new DatabaseJobDispatcher($this->pdo))->request(
            $this->clipId,
            $this->ownerId,
            '15.000',
            '17.000',
            new ReframeSubmission('1:1', 'center', '', '', '')
        );

        self::assertNotNull($second);
        self::assertSame(2, $second->revision());
        self::assertSame(2, $this->jobCount());
        self::assertSame(1, $this->profileCount(1));
        self::assertSame(1, $this->profileCount(2));
        self::assertSame(1, $this->keyframeCount(1));
        self::assertSame(0, $this->keyframeCount(2));
        $firstPlan = $profiles->findForClipRevision($this->clipId, 1);
        $secondPlan = $profiles->findForClipRevision($this->clipId, 2);
        self::assertNotNull($firstPlan);
        self::assertNotNull($secondPlan);
        self::assertSame('manual', $firstPlan->mode());
        self::assertSame('9:16', $firstPlan->aspectRatio()->value());
        self::assertSame('0.250000', $firstPlan->keyframes()[0]->centerXDecimal());
        self::assertSame('center', $secondPlan->mode());
        self::assertSame('1:1', $secondPlan->aspectRatio()->value());
        $jobs = $this->pdo->prepare('SELECT payload_json FROM processing_jobs WHERE project_id = ? ORDER BY id');
        $jobs->execute([$this->projectId]);
        self::assertSame([
            ['clip_id' => $this->clipId, 'render_revision' => 1],
            ['clip_id' => $this->clipId, 'render_revision' => 2],
        ], array_map(
            static fn (string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            $jobs->fetchAll(PDO::FETCH_COLUMN)
        ));
        self::assertSame($ledgerBefore, $this->ledgerCount());
    }

    private function connection(): PDO
    {
        return new PDO(
            $this->dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private function concurrentWorkerScript(
        string $base,
        string $readyPath,
        string $attemptPath,
        string $resultPath
    ): string
    {
        return "<?php\ndeclare(strict_types=1);\nrequire " . var_export($base . '/vendor/autoload.php', true) . ";\n"
            . "\$pdo = new PDO(" . var_export($this->dsn, true) . ", getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);\n"
            . "\$pdo->exec('SET SESSION innodb_lock_wait_timeout = 15');\n"
            . 'file_put_contents(' . var_export($readyPath, true) . ", (string) \$pdo->query('SELECT CONNECTION_ID()')->fetchColumn());\n"
            . "\$precise = new App\\Services\\SourceDurationPreflight(\$pdo, new App\\Repositories\\ProjectSourceRepository(\$pdo), new Tests\\Support\\PreciseMediaProcessor(\$pdo, 240000));\n"
            . "\$service = new App\\Services\\ClipRenderRequestService(\$pdo, new App\\Repositories\\ClipRepository(\$pdo), new App\\Repositories\\ProjectRepository(\$pdo), new App\\Repositories\\ClipRenderProfileRepository(\$pdo), new App\\Media\\Reframe\\ReframePlanValidator(), new App\\Services\\DatabaseJobDispatcher(\$pdo), 180, \$precise);\n"
            . 'file_put_contents(' . var_export($attemptPath, true) . ", 'request');\n"
            . "\$receipt = \$service->request({$this->clipId}, {$this->ownerId}, '12.500', '35.250');\n"
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
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $statement->execute([$connectionId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && strtoupper((string) $row['trx_state']) === 'LOCK WAIT') {
                return (string) $row['trx_query'];
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        self::fail('The competing request did not enter an InnoDB lock wait.');
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

    private function service(PDO $pdo, JobDispatcher $dispatcher): ClipRenderRequestService
    {
        return new ClipRenderRequestService(
            $pdo,
            new ClipRepository($pdo),
            new ProjectRepository($pdo),
            new ClipRenderProfileRepository($pdo),
            new ReframePlanValidator(),
            $dispatcher,
            180,
            $this->sourceDurations($pdo)
        );
    }

    private function sourceDurations(PDO $pdo): \App\Services\SourceDurationPreflight
    {
        return new \App\Services\SourceDurationPreflight($pdo,new \App\Repositories\ProjectSourceRepository($pdo),new \Tests\Support\PreciseMediaProcessor($pdo,240000));
    }

    /** @return array<string, mixed> */
    private function clipState(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, render_start_time, render_end_time, render_revision, render_error_code,
                    approved_at, render_requested_at
             FROM clips WHERE id = ?'
        );
        $statement->execute([$this->clipId]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array{string, string} */
    private function projectState(): array
    {
        $statement = $this->pdo->prepare('SELECT status, progress FROM projects WHERE id = ?');
        $statement->execute([$this->projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [(string) $row['status'], (string) $row['progress']];
    }

    private function jobCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM processing_jobs WHERE project_id = ?');
        $statement->execute([$this->projectId]);

        return (int) $statement->fetchColumn();
    }

    private function profileCount(int $revision): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM clip_render_profiles WHERE clip_id = ? AND render_revision = ?'
        );
        $statement->execute([$this->clipId, $revision]);

        return (int) $statement->fetchColumn();
    }

    private function keyframeCount(int $revision): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM clip_reframe_keyframes k '
            . 'INNER JOIN clip_render_profiles p ON p.id = k.render_profile_id '
            . 'WHERE p.clip_id = ? AND p.render_revision = ?'
        );
        $statement->execute([$this->clipId, $revision]);

        return (int) $statement->fetchColumn();
    }

    private function ledgerCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?');
        $statement->execute([$this->ownerId]);

        return (int) $statement->fetchColumn();
    }
}
