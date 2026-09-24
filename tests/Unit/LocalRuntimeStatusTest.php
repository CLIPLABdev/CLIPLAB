<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LocalRuntimeStatus;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class LocalRuntimeStatusTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliplab-local-status-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    public function testReportsOnlyAggregateQueueAndCapabilityData(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE processing_jobs ('
            . 'id INTEGER PRIMARY KEY, queue_name TEXT, type TEXT, status TEXT, available_at TEXT, leased_until TEXT, '
            . 'payload_json TEXT, worker_id TEXT)'
        );
        $insert = $pdo->prepare(
            'INSERT INTO processing_jobs '
            . '(id, queue_name, type, status, available_at, leased_until, payload_json, worker_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([1, 'media', 'probe_source', 'queued', '2026-09-06 22:00:00', null, 'PAYLOAD_CANARY', null]);
        $insert->execute([2, 'media', 'render_clip', 'retry', '2026-09-07 01:00:00', null, '{}', null]);
        $insert->execute([3, 'media', 'analyze_video', 'running', '2026-09-06 20:00:00', '2026-09-06 22:59:00', '{}', 'WORKER_CANARY']);
        $insert->execute([4, 'other', 'probe_source', 'queued', '2026-09-06 20:00:00', null, '{}', null]);

        // This diagnostic checks an executable path; it does not invoke FFmpeg.
        $ffmpeg = PHP_BINARY;
        $ffprobe = PHP_BINARY;
        $media = [
            'configured_max_upload_bytes' => 524288000,
            'effective_upload_bytes' => 41523610,
            'php_upload_max_bytes' => 41943040,
            'php_post_max_bytes' => 41943040,
            'private_root' => $this->temporaryDirectory,
            'ffmpeg_binary' => $ffmpeg,
            'ffprobe_binary' => $ffprobe,
            'download_timeout_seconds' => 120,
            'process_timeout_seconds' => 60,
            'render_timeout_seconds' => 240,
            'queue' => ['lease_seconds' => 300],
        ];

        $status = (new LocalRuntimeStatus($pdo))->collect(
            $media,
            ['http_timeout_seconds' => 180],
            'media',
            new DateTimeImmutable('2026-09-06 23:00:00', new DateTimeZone('UTC'))
        );

        self::assertTrue($status['ok']);
        self::assertSame(1, $status['queue']['eligible_now']);
        self::assertSame(1, $status['queue']['expired_running']);
        self::assertSame(3, $status['queue']['total']);
        self::assertSame([
            ['type' => 'analyze_video', 'status' => 'running', 'count' => 1, 'eligible_now' => 0, 'expired_running' => 1],
            ['type' => 'probe_source', 'status' => 'queued', 'count' => 1, 'eligible_now' => 1, 'expired_running' => 0],
            ['type' => 'render_clip', 'status' => 'retry', 'count' => 1, 'eligible_now' => 0, 'expired_running' => 0],
        ], $status['queue']['groups']);
        self::assertSame(270, $status['worker']['required_lease_seconds']);
        self::assertTrue($status['worker']['lease_valid']);
        self::assertTrue($status['worker']['ffmpeg_available']);
        self::assertTrue($status['worker']['ffprobe_available']);
        self::assertSame(41523610, $status['upload']['effective_bytes']);

        $json = json_encode($status, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PAYLOAD_CANARY', $json);
        self::assertStringNotContainsString('WORKER_CANARY', $json);
        self::assertStringNotContainsString($this->temporaryDirectory, $json);
        self::assertArrayNotHasKey('payload_json', $status['queue']['groups'][0]);
    }

    public function testMissingBinaryIsNotReportedAsAvailable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE processing_jobs (queue_name TEXT, type TEXT, status TEXT, available_at TEXT, leased_until TEXT)');
        $status = (new LocalRuntimeStatus($pdo))->collect([
            'private_root' => $this->temporaryDirectory,
            'ffmpeg_binary' => $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'does-not-exist.exe',
            'ffprobe_binary' => PHP_BINARY,
            'queue' => ['lease_seconds' => 300],
        ], []);
        self::assertFalse($status['worker']['ffmpeg_available']);
        self::assertTrue($status['worker']['ffprobe_available']);
    }
}
