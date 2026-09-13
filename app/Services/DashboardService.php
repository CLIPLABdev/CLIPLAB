<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

final class DashboardService
{
    private DateTimeZone $timezone;
    private ?\Closure $quotaSnapshot;

    public function __construct(private ProjectRepository $projects, private CreditTransactionRepository $credits, private UserRepository $users, ?DateTimeZone $timezone = null, ?callable $quotaSnapshot = null)
    {
        $this->timezone = $timezone ?? new DateTimeZone((string) Env::get('APP_TIMEZONE', 'UTC'));
        $this->quotaSnapshot = $quotaSnapshot === null ? null : \Closure::fromCallable($quotaSnapshot);
    }

    /** @return array{projects: int, processed: int, minutes: int, minutes_used: int, credits: int, storage_bytes: int, recent: array<int, array<string, int|string>>} */
    public function forUser(int $userId, ?DateTimeImmutable $now = null): array
    {
        $summary = $this->projects->summaryForUser($userId);
        if ($this->quotaSnapshot !== null) {
            $usage = ($this->quotaSnapshot)($userId, $now);
            return [
                'projects' => $summary['projects'],
                'processed' => $summary['processed'],
                'minutes' => $usage['minutes_remaining'],
                'minutes_used' => $usage['minutes_used'],
                'credits' => $usage['credits'],
                'storage_bytes' => $usage['storage_bytes'],
                'recent' => $this->projects->recentForUser($userId),
            ];
        }
        $reference = ($now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
        $startsAt = $reference->modify('first day of this month')->setTime(0, 0);
        $endsAt = $startsAt->modify('+1 month');
        $usedSeconds = $this->projects->processedSecondsForUserInPeriod($userId, $startsAt, $endsAt);
        $minutesUsed = $usedSeconds === 0 ? 0 : (int) ceil($usedSeconds / 60);
        $profile = $this->users->findDashboardProfile($userId);
        $monthlyMinutes = (int) ($profile['monthly_minutes'] ?? 0);

        return [
            'projects' => $summary['projects'],
            'processed' => $summary['processed'],
            'minutes' => max($monthlyMinutes - $minutesUsed, 0),
            'minutes_used' => $minutesUsed,
            'credits' => $this->credits->latestBalanceForUser($userId),
            'storage_bytes' => $summary['storage_bytes'],
            'recent' => $this->projects->recentForUser($userId),
        ];
    }
}
