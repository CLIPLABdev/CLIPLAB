<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Ai\ViralClipPrompt;
use App\Core\Migrator;
use App\Repositories\AiAnalysisRepository;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\AiPipelineStarter;
use App\Services\CreditReservationService;
use App\Services\DatabaseJobDispatcher;
use App\Services\PlanQuotaService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AiPipelineStarterTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $projectId;
    private int $sourceId;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        [$this->planId, $this->userId, $this->projectId, $this->sourceId] = $this->fixture(10, 121);
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
        $this->pdo->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testSchedulesExactPayloadAndReservesAutomaticCostOnlyOnce(): void
    {
        $starter = $this->starter();

        $first = $starter->schedule($this->projectId, $this->sourceId, 121);
        $second = $starter->schedule($this->projectId, $this->sourceId, 121);

        self::assertSame($first->analysisId(), $second->analysisId());
        self::assertSame($first->reservationId(), $second->reservationId());
        self::assertFalse($second->created());
        self::assertNotNull($first->reservationId());
        self::assertSame(4, $this->balance());
        $reservation = $this->row('SELECT user_id, project_id, operation, units, status, idempotency_key FROM credit_reservations WHERE id = ?', [$first->reservationId()]);
        self::assertSame([
            'user_id' => $this->userId,
            'project_id' => $this->projectId,
            'operation' => 'ai_analysis',
            'units' => 6,
            'status' => 'reserved',
            'idempotency_key' => hash('sha256', $this->userId . ':ai:analyze:' . $this->projectId . ':' . ViralClipPrompt::VERSION),
        ], $reservation);
        $job = $this->row('SELECT type, project_id, payload_json FROM processing_jobs WHERE project_id = ?', [$this->projectId]);
        self::assertSame('analyze_video', $job['type']);
        self::assertSame($this->projectId, $job['project_id']);
        self::assertSame([
            'analysis_id' => $first->analysisId(),
            'reservation_id' => $first->reservationId(),
            'source_id' => $this->sourceId,
        ], json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['status' => 'ai_queued', 'progress' => 75], $this->row('SELECT status, progress FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame(1, $this->tableCount('processing_jobs'));
        self::assertSame(1, $this->tableCount('ai_analyses'));
        self::assertSame(1, $this->tableCount('credit_reservations'));
        $usage = $this->row('SELECT processed_duration_seconds, usage_recorded_at FROM projects WHERE id = ?', [$this->projectId]);
        self::assertSame(121, $usage['processed_duration_seconds']);
        self::assertNotNull($usage['usage_recorded_at']);
    }

    public function testInsufficientBalanceCreatesAnalysisButNoReservationOrJob(): void
    {
        $this->pdo->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('UPDATE users SET credits = 0 WHERE id = ?')->execute([$this->userId]);

        $receipt = $this->starter()->schedule($this->projectId, $this->sourceId, 121);

        self::assertNull($receipt->reservationId());
        self::assertSame('queued', $receipt->status());
        self::assertSame(1, $this->tableCount('ai_analyses'));
        self::assertSame(0, $this->tableCount('credit_reservations'));
        self::assertSame(0, $this->tableCount('processing_jobs'));
        self::assertSame(['processed_duration_seconds' => 0, 'usage_recorded_at' => null], $this->row('SELECT processed_duration_seconds, usage_recorded_at FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame([
            'status' => 'awaiting_credits',
            'progress' => 75,
            'error_code' => 'insufficient_credits',
            'error_message' => 'Créditos insuficientes para iniciar a análise.',
        ], $this->row('SELECT status, progress, error_code, error_message FROM projects WHERE id = ?', [$this->projectId]));
    }

    public function testMonthlyLimitBlocksBeforeCreditReservationAndUsageRecording(): void
    {
        $this->pdo->prepare('UPDATE plans SET monthly_minutes = 2 WHERE id = ?')->execute([$this->planId]);

        $receipt = $this->starter()->schedule($this->projectId, $this->sourceId, 121);

        self::assertNull($receipt->reservationId());
        self::assertSame(10, $this->balance());
        self::assertSame(0, $this->tableCount('credit_reservations'));
        self::assertSame(0, $this->tableCount('processing_jobs'));
        self::assertSame([
            'status' => 'failed',
            'error_code' => 'monthly_minutes_exceeded',
            'processed_duration_seconds' => 0,
            'usage_recorded_at' => null,
        ], $this->row('SELECT status, error_code, processed_duration_seconds, usage_recorded_at FROM projects WHERE id = ?', [$this->projectId]));
    }

    public function testRejectsSourceDurationOrOwnershipMismatchBeforeDebiting(): void
    {
        foreach ([
            [$this->projectId, $this->sourceId, 120],
            [$this->projectId, $this->sourceId + 999999, 121],
        ] as [$projectId, $sourceId, $duration]) {
            try {
                $this->starter()->schedule($projectId, $sourceId, $duration);
                self::fail('A mismatched source contract was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(10, $this->balance());
                self::assertSame(0, $this->tableCount('credit_reservations'));
                self::assertSame(0, $this->tableCount('processing_jobs'));
            }
        }
    }

    public function testLateDuplicateDoesNotRegressTerminalProjectState(): void
    {
        $starter = $this->starter();
        $starter->schedule($this->projectId, $this->sourceId, 121);
        $this->pdo->prepare("UPDATE projects SET status = 'suggestions_ready', progress = 100 WHERE id = ?")->execute([$this->projectId]);

        $starter->schedule($this->projectId, $this->sourceId, 121);

        self::assertSame(['status' => 'suggestions_ready', 'progress' => 100], $this->row('SELECT status, progress FROM projects WHERE id = ?', [$this->projectId]));
    }

    public function testLegacyReadyReplayQueuesAiWithoutReducingPersistedProgress(): void
    {
        $this->pdo->prepare("UPDATE projects SET status = 'ready', progress = 100 WHERE id = ?")->execute([$this->projectId]);

        $this->starter()->schedule($this->projectId, $this->sourceId, 121);

        self::assertSame(
            ['status' => 'ai_queued', 'progress' => 100],
            $this->row('SELECT status, progress FROM projects WHERE id = ?', [$this->projectId])
        );
        $projects = new ProjectRepository($this->pdo);
        foreach (['uploading_ai', 'waiting_ai_file', 'analyzing', 'identifying_clips'] as $status) {
            $projects->advanceProcessingState($this->projectId, $status);
            self::assertSame(
                ['status' => $status, 'progress' => 100],
                $this->row('SELECT status, progress FROM projects WHERE id = ?', [$this->projectId])
            );
        }
    }

    private function starter(): AiPipelineStarter
    {
        $credits = new CreditReservationService(
            $this->pdo,
            new CreditReservationRepository($this->pdo),
            new CreditTransactionRepository($this->pdo),
            2
        );

        return new AiPipelineStarter(
            $this->pdo,
            new ProjectRepository($this->pdo),
            new ProjectSourceRepository($this->pdo),
            new AiAnalysisRepository($this->pdo),
            $credits,
            new DatabaseJobDispatcher($this->pdo, 'media', 3),
            ViralClipPrompt::VERSION,
            'gemini-2.5-flash',
            new PlanQuotaService($this->pdo)
        );
    }

    /** @return array{int, int, int, int} */
    private function fixture(int $credits, int $duration): array
    {
        $suffix = bin2hex(random_bytes(8));
        $features = '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":524288000,"storage_bytes":1073741824}}';
        $this->pdo->prepare('INSERT INTO plans (slug, name, monthly_minutes, features) VALUES (?, ?, 1000, ?)')->execute(['ai-starter-' . $suffix, 'AI Starter ' . $suffix, $features]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, ?)')->execute(['AI starter fixture', 'ai-starter-' . $suffix . '@example.test', 'not-a-real-hash', $planId, $credits]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'AI project', 'ready', 70)")->execute([$userId]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes, duration_seconds, width, height, video_codec, has_audio, status) VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, ?, 1920, 1080, 'h264', 0, 'ready')")->execute([$projectId, 'imports/' . $projectId . '/video.mp4', $duration]);
        $sourceId = (int) $this->pdo->lastInsertId();
        if ($credits > 0) {
            $this->pdo->prepare("INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (?, 'credit', ?, ?, 'test_fixture', 'AI starter credits')")->execute([$userId, $credits, $credits]);
        }

        return [$planId, $userId, $projectId, $sourceId];
    }

    /** @param list<mixed> $params @return array<string, mixed> */
    private function row(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function tableCount(string $table): int
    {
        $column = $table === 'credit_reservations' ? 'user_id' : 'project_id';
        $value = $table === 'credit_reservations' ? $this->userId : $this->projectId;
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?');
        $statement->execute([$value]);

        return (int) $statement->fetchColumn();
    }

    private function balance(): int
    {
        return (new CreditTransactionRepository($this->pdo))->latestBalanceForUser($this->userId);
    }
}
