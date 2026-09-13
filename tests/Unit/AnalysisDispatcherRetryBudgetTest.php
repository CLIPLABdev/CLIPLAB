<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DatabaseJobDispatcher;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnalysisDispatcherRetryBudgetTest extends TestCase
{
    public function testReplayingAnExistingAnalysisPreservesItsOriginalBudgetAfterConfigChanges(): void
    {
        $pdo = $this->database();
        $old = new DatabaseJobDispatcher($pdo, 'media', 3);
        $id = $old->dispatch('analyze_video', 11, ['analysis_id' => 31], 'analysis-original');
        $configured = new DatabaseJobDispatcher($pdo, 'media', 6);

        try {
            $replayedId = $configured->dispatch('analyze_video', 11, ['analysis_id' => 31], 'analysis-original');
        } catch (\RuntimeException $exception) {
            self::fail('Retry configuration must not invalidate an existing analysis identity: ' . $exception->getMessage());
        }
        self::assertSame($id, $replayedId);
        self::assertSame(3, (int) $pdo->query('SELECT max_attempts FROM processing_jobs')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn());
        $configured->dispatch('analyze_video', 12, ['analysis_id' => 32], 'analysis-new');
        self::assertSame([3, 6], array_map('intval', $pdo->query('SELECT max_attempts FROM processing_jobs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testChangingAnExistingAnalysisPayloadStillRejectsTheConflict(): void
    {
        $pdo = $this->database();
        (new DatabaseJobDispatcher($pdo, 'media', 3))->dispatch('analyze_video', 11, ['analysis_id' => 31], 'original');
        $this->expectException(\RuntimeException::class);
        (new DatabaseJobDispatcher($pdo, 'media', 6))->dispatch('analyze_video', 11, ['analysis_id' => 99], 'original');
    }

    public function testNonAnalysisJobsKeepTheirStrictBudgetIdempotencyContract(): void
    {
        $pdo = $this->database();
        (new DatabaseJobDispatcher($pdo, 'media', 3))->dispatch('probe_source', 11, ['source_id' => 21], 'original');
        $this->expectException(\RuntimeException::class);
        (new DatabaseJobDispatcher($pdo, 'media', 6))->dispatch('probe_source', 11, ['source_id' => 21], 'original');
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-09-07 12:00:00');
        $pdo->exec('CREATE TABLE processing_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue_name TEXT, type TEXT, project_id INTEGER, payload_json TEXT, idempotency_key TEXT, max_attempts INTEGER, available_at TEXT)');
        return $pdo;
    }
}
