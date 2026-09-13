<?php

declare(strict_types=1);

namespace App\Services;

use App\Plans\PlanLimitExceeded;
use App\Plans\PlanLimits;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDO;
use RuntimeException;

final class PlanQuotaService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function snapshotForUser(int $userId, ?DateTimeImmutable $now = null): array
    {
        $account = $this->account($userId, false);
        $limits = $this->limitsFromAccount($account);
        $minutesUsed = $this->minutesUsed($userId, $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')), false);
        $storageBytes = $this->storageUsed($userId, false);
        $monthlyMinutes = (int) $account['monthly_minutes'];

        return [
            'user' => [
                'id' => (int) $account['user_id'],
                'name' => (string) $account['user_name'],
                'email' => (string) $account['email'],
                'status' => (string) $account['user_status'],
            ],
            'plan' => [
                'id' => (int) $account['plan_id'],
                'slug' => (string) $account['slug'],
                'name' => (string) $account['plan_name'],
                'price_cents' => (int) $account['price_cents'],
                'monthly_minutes' => $monthlyMinutes,
                'included_credits' => (int) $account['included_credits'],
                'is_active' => (bool) $account['is_active'],
                'features' => $limits->toArray(),
            ],
            'credits' => $this->creditBalance($userId, (int) $account['mirror_credits']),
            'minutes_used' => $minutesUsed,
            'minutes_remaining' => max(0, $monthlyMinutes - $minutesUsed),
            'storage_bytes' => $storageBytes,
            'storage_remaining_bytes' => max(0, $limits->storageBytes() - $storageBytes),
        ];
    }

    public function assertAdditionalStorageAvailable(int $userId, int $bytes): void
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('Additional storage bytes cannot be negative.');
        }
        $this->requireTransaction();
        $account = $this->account($userId, true);
        $this->assertActiveAccount($account, $bytes);
        $limit = $this->limitsFromAccount($account)->storageBytes();
        $used = $this->storageUsed($userId, true);
        if ($bytes > max(0, $limit - $used)) {
            throw new PlanLimitExceeded('storage_limit_exceeded', $limit, $used, $bytes);
        }
    }

    public function lockForAdmission(int $userId): void
    {
        $this->requireTransaction();
        $account = $this->account($userId, true);
        $this->assertActiveAccount($account, 0);
    }

    public function assertUploadBytesAllowed(int $userId, int $bytes): void
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('Upload bytes must be positive.');
        }
        $this->requireTransaction();
        $account = $this->account($userId, true);
        $this->assertActiveAccount($account, $bytes);
        $limit = $this->limitsFromAccount($account)->maxUploadBytes();
        if ($bytes > $limit) {
            throw new PlanLimitExceeded('upload_limit_exceeded', $limit, 0, $bytes);
        }
    }

    public function assertProcessingMinutesAvailable(
        int $userId,
        int $projectId,
        int $durationSeconds,
        ?DateTimeImmutable $now = null
    ): int {
        if ($projectId < 1 || $durationSeconds < 1) {
            throw new InvalidArgumentException('Project and duration must be positive.');
        }
        $this->requireTransaction();
        $account = $this->account($userId, true);
        $this->assertActiveAccount($account, intdiv($durationSeconds - 1, 60) + 1);
        $project = $this->lockedProject($projectId, $userId);
        $requested = intdiv($durationSeconds - 1, 60) + 1;

        if ($project['usage_recorded_at'] !== null) {
            if ((int) $project['processed_duration_seconds'] !== $durationSeconds) {
                throw new InvalidArgumentException('Recorded processing duration cannot be changed.');
            }

            return $requested;
        }
        if ((int) $project['processed_duration_seconds'] !== 0) {
            throw new RuntimeException('Project usage is inconsistent.');
        }

        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $used = $this->minutesUsed($userId, $instant, true);
        $limit = (int) $account['monthly_minutes'];
        if ($requested > max(0, $limit - $used)) {
            throw new PlanLimitExceeded('monthly_minutes_exceeded', $limit, $used, $requested);
        }

        return $requested;
    }

    public function recordProcessingUsage(
        int $userId,
        int $projectId,
        int $durationSeconds,
        ?DateTimeImmutable $now = null
    ): void {
        $instant = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->assertProcessingMinutesAvailable($userId, $projectId, $durationSeconds, $instant);
        $project = $this->lockedProject($projectId, $userId);
        if ($project['usage_recorded_at'] !== null) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE projects
             SET processed_duration_seconds = :duration_seconds, usage_recorded_at = :recorded_at
             WHERE id = :project_id AND user_id = :user_id AND usage_recorded_at IS NULL'
        );
        $statement->execute([
            'duration_seconds' => $durationSeconds,
            'recorded_at' => $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Project usage could not be recorded.');
        }
    }

    /** @return array<string, mixed> */
    private function account(int $userId, bool $lock): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('User identifier must be positive.');
        }
        $sql = 'SELECT u.id AS user_id, u.name AS user_name, u.email, u.status AS user_status,
                       u.credits AS mirror_credits, p.id AS plan_id, p.slug, p.name AS plan_name,
                       p.price_cents, p.monthly_minutes, p.credits AS included_credits,
                       p.features, p.is_active
                FROM users u
                INNER JOIN plans p ON p.id = u.plan_id
                WHERE u.id = :user_id
                LIMIT 1';
        if ($lock && $this->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['user_id' => $userId]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new RuntimeException('Account quota is unavailable.');
        }

        return $account;
    }

    /** @param array<string, mixed> $account */
    private function limitsFromAccount(array $account): PlanLimits
    {
        try {
            $features = json_decode((string) $account['features'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($features)) {
                throw new JsonException('Features are not an object.');
            }

            return PlanLimits::fromFeatures($features);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Account plan limits are invalid.', 0, $exception);
        }
    }

    private function creditBalance(int $userId, int $fallback): int
    {
        $statement = $this->pdo->prepare(
            'SELECT balance_after FROM credit_transactions
             WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $balance = $statement->fetchColumn();

        return $balance === false ? $fallback : (int) $balance;
    }

    private function minutesUsed(int $userId, DateTimeImmutable $now, bool $current): int
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $startsAt = $utc->modify('first day of this month')->setTime(0, 0, 0);
        $endsAt = $startsAt->modify('+1 month');
        $sql = 'SELECT processed_duration_seconds FROM projects
             WHERE user_id = :user_id
               AND usage_recorded_at >= :starts_at
               AND usage_recorded_at < :ends_at
               AND processed_duration_seconds > 0';
        if ($current && $this->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
        ]);
        $minutes = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $duration) {
            $seconds = (int) $duration;
            $minutes += intdiv($seconds - 1, 60) + 1;
        }

        return $minutes;
    }

    private function storageUsed(int $userId, bool $current): int
    {
        if ($current && $this->driver() === 'mysql') {
            return $this->currentStorageUsed($userId);
        }
        $sources = $this->sum(
            "SELECT COALESCE(SUM(CASE WHEN s.object_key IS NOT NULL AND s.object_key <> '' THEN COALESCE(s.size_bytes, 0) ELSE 0 END), 0)
             FROM project_sources s INNER JOIN projects p ON p.id = s.project_id WHERE p.user_id = :user_id",
            $userId
        );
        $clips = $this->sum(
            "SELECT COALESCE(SUM(
                 CASE WHEN c.output_file IS NOT NULL AND c.output_file <> '' THEN COALESCE(c.output_size_bytes, 0) ELSE 0 END
                 + CASE WHEN c.thumbnail IS NOT NULL AND c.thumbnail <> '' THEN COALESCE(c.thumbnail_size_bytes, 0) ELSE 0 END
             ), 0)
             FROM clips c INNER JOIN projects p ON p.id = c.project_id WHERE p.user_id = :user_id",
            $userId
        );

        $logos = $this->sum('SELECT COALESCE(SUM(size_bytes), 0) FROM user_brand_logos WHERE user_id = :user_id', $userId);
        $thumbnails = $this->sum("SELECT COALESCE(SUM(size_bytes), 0) FROM clip_thumbnails WHERE user_id = :user_id AND status = 'ready' AND object_key IS NOT NULL", $userId);
        return $sources + $clips + $logos + $thumbnails;
    }

    private function currentStorageUsed(int $userId): int
    {
        $sourceQuery = $this->pdo->prepare(
            'SELECT s.object_key, s.size_bytes
             FROM project_sources s INNER JOIN projects p ON p.id = s.project_id
             WHERE p.user_id = :user_id FOR UPDATE'
        );
        $sourceQuery->execute(['user_id' => $userId]);
        $total = 0;
        foreach ($sourceQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['object_key'] !== null && (string) $row['object_key'] !== '') {
                $total += max(0, (int) ($row['size_bytes'] ?? 0));
            }
        }

        $clipQuery = $this->pdo->prepare(
            'SELECT c.output_file, c.output_size_bytes, c.thumbnail, c.thumbnail_size_bytes
             FROM clips c INNER JOIN projects p ON p.id = c.project_id
             WHERE p.user_id = :user_id FOR UPDATE'
        );
        $clipQuery->execute(['user_id' => $userId]);
        foreach ($clipQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['output_file'] !== null && (string) $row['output_file'] !== '') {
                $total += max(0, (int) ($row['output_size_bytes'] ?? 0));
            }
            if ($row['thumbnail'] !== null && (string) $row['thumbnail'] !== '') {
                $total += max(0, (int) ($row['thumbnail_size_bytes'] ?? 0));
            }
        }

        $logoQuery = $this->pdo->prepare('SELECT size_bytes FROM user_brand_logos WHERE user_id = :user_id FOR UPDATE');
        $logoQuery->execute(['user_id' => $userId]);
        foreach ($logoQuery->fetchAll(PDO::FETCH_COLUMN) as $bytes) {
            $total += max(0, (int) $bytes);
        }
        $thumbnailQuery = $this->pdo->prepare("SELECT size_bytes FROM clip_thumbnails WHERE user_id = :user_id AND status = 'ready' AND object_key IS NOT NULL FOR UPDATE");
        $thumbnailQuery->execute(['user_id' => $userId]);
        foreach ($thumbnailQuery->fetchAll(PDO::FETCH_COLUMN) as $bytes) {
            $total += max(0, (int) $bytes);
        }
        return $total;
    }

    private function sum(string $sql, int $userId): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['user_id' => $userId]);

        return max(0, (int) $statement->fetchColumn());
    }

    /** @return array{processed_duration_seconds:mixed,usage_recorded_at:mixed} */
    private function lockedProject(int $projectId, int $userId): array
    {
        $sql = 'SELECT processed_duration_seconds, usage_recorded_at FROM projects
                WHERE id = :project_id AND user_id = :user_id LIMIT 1';
        if ($this->driver() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $project = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($project)) {
            throw new RuntimeException('Project quota is unavailable.');
        }

        return $project;
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Quota admission requires an active transaction.');
        }
    }

    /** @param array<string,mixed> $account */
    private function assertActiveAccount(array $account, int $requested): void
    {
        if ((string) $account['user_status'] !== 'active' || !(bool) $account['is_active']) {
            throw new PlanLimitExceeded('account_inactive', 0, 0, max(0, $requested));
        }
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
