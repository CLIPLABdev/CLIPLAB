<?php

declare(strict_types=1);

namespace App\Services;

use App\Process\ProcessRunner;
use App\Queue\WorkerLeaseBudget;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class LocalRuntimeStatus
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $media
     * @param array<string, mixed> $gemini
     * @return array<string, mixed>
     */
    public function collect(
        array $media,
        array $gemini,
        string $queue = 'media',
        ?DateTimeImmutable $now = null
    ): array {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            "SELECT type, status, COUNT(*) AS job_count,\n"
            . "SUM(CASE WHEN status IN ('queued', 'retry') AND available_at <= :eligible_now THEN 1 ELSE 0 END) AS eligible_now,\n"
            . "SUM(CASE WHEN status = 'running' AND leased_until < :expired_now THEN 1 ELSE 0 END) AS expired_running\n"
            . "FROM processing_jobs WHERE queue_name = :queue GROUP BY type, status ORDER BY type, status"
        );
        $statement->execute([
            'eligible_now' => $timestamp,
            'expired_now' => $timestamp,
            'queue' => $queue,
        ]);

        $groups = [];
        $total = 0;
        $eligible = 0;
        $expired = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $group = [
                'type' => (string) ($row['type'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'count' => (int) ($row['job_count'] ?? 0),
                'eligible_now' => (int) ($row['eligible_now'] ?? 0),
                'expired_running' => (int) ($row['expired_running'] ?? 0),
            ];
            $groups[] = $group;
            $total += $group['count'];
            $eligible += $group['eligible_now'];
            $expired += $group['expired_running'];
        }

        $leaseSeconds = (int) ($media['queue']['lease_seconds'] ?? 0);
        $requiredLeaseSeconds = WorkerLeaseBudget::requiredSeconds(
            (int) ($gemini['http_timeout_seconds'] ?? 180),
            (int) ($media['render_timeout_seconds'] ?? 240),
            (int) ($media['download_timeout_seconds'] ?? 120),
            (int) ($media['process_timeout_seconds'] ?? 60),
            30,
            true,
        ($media['youtube_import_enabled'] ?? false) ? (int) ($media['yt_dlp_timeout_seconds'] ?? 60) : 0,
        ($media['youtube_import_enabled'] ?? false) ? (int) ($media['process_timeout_seconds'] ?? 60) : 0
        );
        $privateRoot = (string) ($media['private_root'] ?? '');

        return [
            'ok' => true,
            'generated_at_utc' => $now->format(DATE_ATOM),
            'database' => ['driver' => (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)],
            'upload' => [
                'configured_bytes' => (int) ($media['configured_max_upload_bytes'] ?? $media['max_upload_bytes'] ?? 0),
                'effective_bytes' => (int) ($media['effective_upload_bytes'] ?? 0),
                'php_upload_max_bytes' => (int) ($media['php_upload_max_bytes'] ?? 0),
                'php_post_max_bytes' => (int) ($media['php_post_max_bytes'] ?? 0),
            ],
            'worker' => [
                'proc_open_available' => function_exists('proc_open'),
                'ffmpeg_available' => $this->processBinaryAvailable((string) ($media['ffmpeg_binary'] ?? ''), $privateRoot),
                'ffprobe_available' => $this->processBinaryAvailable((string) ($media['ffprobe_binary'] ?? ''), $privateRoot),
                'private_storage_exists' => is_dir($privateRoot),
                'private_storage_readable' => is_readable($privateRoot),
                'private_storage_writable' => is_writable($privateRoot),
                'lease_seconds' => $leaseSeconds,
                'required_lease_seconds' => $requiredLeaseSeconds,
                'lease_valid' => $leaseSeconds >= $requiredLeaseSeconds,
            ],
            'queue' => [
                'name' => $queue,
                'total' => $total,
                'eligible_now' => $eligible,
                'expired_running' => $expired,
                'groups' => $groups,
            ],
        ];
    }

    private function processBinaryAvailable(string $binary, string $privateRoot): bool
    {
        if ($binary === '' || !is_dir($privateRoot)) {
            return false;
        }

        try {
            return (new ProcessRunner([$binary], $privateRoot))->isExecutableAvailable($binary);
        } catch (Throwable) {
            return false;
        }
    }
}
