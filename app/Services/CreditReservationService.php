<?php

declare(strict_types=1);

namespace App\Services;

use App\Credits\CreditReservation;
use App\Credits\InsufficientCredits;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CreditReservationService
{
    private const MAX_UNITS = 2147483647;
    private const MAX_UNSIGNED_INT = 4294967295;
    private const REFUND_REASONS = [
        'analysis_failed',
        'ai_unconfigured',
        'ai_provider_rejected',
        'ai_file_failed',
        'ai_response_invalid',
        'analysis_not_found',
        'processing_persistence_failed',
        'ai_timeout',
        'ai_rate_limited',
        'ai_unavailable',
        'opusclip_unavailable',
        'opusclip_failed',
    ];

    private PDO $pdo;
    private CreditReservationRepository $reservations;
    private CreditTransactionRepository $transactions;
    private int $creditsPerMinute;

    public function __construct(
        PDO $pdo,
        CreditReservationRepository $reservations,
        CreditTransactionRepository $transactions,
        int $creditsPerMinute
    ) {
        if ($creditsPerMinute < 1 || $creditsPerMinute > self::MAX_UNITS) {
            throw new InvalidArgumentException('Credits per minute are invalid.');
        }

        $this->pdo = $pdo;
        $this->reservations = $reservations;
        $this->transactions = $transactions;
        $this->creditsPerMinute = $creditsPerMinute;
    }

    public function costForDuration(int $durationSeconds): int
    {
        if ($durationSeconds < 1) {
            throw new InvalidArgumentException('Duration is invalid.');
        }

        $minutes = intdiv($durationSeconds - 1, 60) + 1;
        if ($minutes > intdiv(self::MAX_UNITS, $this->creditsPerMinute)) {
            throw new InvalidArgumentException('Credit cost exceeds the supported limit.');
        }

        return $minutes * $this->creditsPerMinute;
    }

    public function reserve(int $userId, int $projectId, int $units, string $idempotencyKey): CreditReservation
    {
        $this->assertPositiveId($userId);
        $this->assertPositiveId($projectId);
        $this->assertUnits($units);
        $this->assertIdempotencyKey($idempotencyKey);
        $hash = hash('sha256', $userId . ':' . $idempotencyKey);

        return $this->transactional(function () use ($userId, $projectId, $units, $hash): CreditReservation {
            $mirrorBalance = $this->reservations->lockUserCredits($userId, true);
            if ($mirrorBalance === null || !$this->reservations->projectBelongsToUser($projectId, $userId)) {
                throw new InvalidArgumentException('Credit reservation owner or project is invalid.');
            }

            $existing = $this->reservations->findLockedByUserAndKey($userId, $hash);
            $ledgerBalance = $this->transactions->latestLockedBalanceForUser($userId);
            $available = $ledgerBalance ?? $mirrorBalance;

            if ($existing !== null) {
                if ($existing->projectId() !== $projectId || $existing->units() !== $units) {
                    throw new InvalidArgumentException('Credit reservation idempotency conflict.');
                }
                $this->reservations->updateUserBalance($userId, $available);

                return $existing;
            }

            if ($available < $units) {
                throw new InsufficientCredits($available, $units);
            }

            $balanceAfter = $available - $units;
            $reservation = $this->reservations->create($userId, $projectId, $units, $hash);
            $transactionId = $this->transactions->recordReservationEntry(
                $userId,
                'debit',
                $units,
                $balanceAfter,
                $reservation->id(),
                'Reserva de créditos para análise de IA'
            );
            $this->reservations->attachDebit($reservation->id(), $transactionId);
            $this->reservations->updateUserBalance($userId, $balanceAfter);

            return $reservation;
        });
    }

    public function consume(int $reservationId): CreditReservation
    {
        $this->assertPositiveId($reservationId);
        $userId = $this->reservations->userIdForReservation($reservationId);
        if ($userId === null) {
            throw new InvalidArgumentException('Credit reservation does not exist.');
        }

        return $this->transactional(function () use ($reservationId, $userId): CreditReservation {
            if ($this->reservations->lockUserCredits($userId, false) === null) {
                throw new RuntimeException('Credit reservation user does not exist.');
            }
            $reservation = $this->reservations->findLockedById($reservationId);
            if ($reservation === null || $reservation->userId() !== $userId) {
                throw new RuntimeException('Credit reservation changed during transition.');
            }

            $this->transactions->latestLockedBalanceForUser($userId);
            if ($reservation->status() !== 'reserved') {
                return $reservation;
            }

            $this->reservations->markConsumed($reservationId);

            return new CreditReservation(
                $reservation->id(),
                $reservation->userId(),
                $reservation->projectId(),
                $reservation->units(),
                'consumed'
            );
        });
    }

    public function refund(int $reservationId, string $reason): CreditReservation
    {
        $this->assertPositiveId($reservationId);
        if (!in_array($reason, self::REFUND_REASONS, true)) {
            throw new InvalidArgumentException('Credit refund reason is invalid.');
        }
        $userId = $this->reservations->userIdForReservation($reservationId);
        if ($userId === null) {
            throw new InvalidArgumentException('Credit reservation does not exist.');
        }

        return $this->transactional(function () use ($reservationId, $reason, $userId): CreditReservation {
            $mirrorBalance = $this->reservations->lockUserCredits($userId, false);
            if ($mirrorBalance === null) {
                throw new RuntimeException('Credit reservation user does not exist.');
            }
            $reservation = $this->reservations->findLockedById($reservationId);
            if ($reservation === null || $reservation->userId() !== $userId) {
                throw new RuntimeException('Credit reservation changed during transition.');
            }
            $ledgerBalance = $this->transactions->latestLockedBalanceForUser($userId);
            $available = $ledgerBalance ?? $mirrorBalance;

            if ($reservation->status() !== 'reserved') {
                $this->reservations->updateUserBalance($userId, $available);

                return $reservation;
            }

            $balanceAfter = $available + $reservation->units();
            if ($balanceAfter > self::MAX_UNSIGNED_INT) {
                throw new RuntimeException('Credit refund would exceed the supported balance.');
            }
            $transactionId = $this->transactions->recordReservationEntry(
                $userId,
                'credit',
                $reservation->units(),
                $balanceAfter,
                $reservation->id(),
                'Reembolso de créditos: ' . $reason
            );
            $this->reservations->markRefunded($reservation->id(), $transactionId);
            $this->reservations->updateUserBalance($userId, $balanceAfter);

            return new CreditReservation(
                $reservation->id(),
                $reservation->userId(),
                $reservation->projectId(),
                $reservation->units(),
                'refunded'
            );
        });
    }

    /** @param callable(): CreditReservation $operation */
    private function transactional(callable $operation): CreditReservation
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction && !$this->pdo->beginTransaction()) {
            throw new RuntimeException('Credit transaction could not start.');
        }

        try {
            $result = $operation();
            if ($ownsTransaction && !$this->pdo->commit()) {
                throw new RuntimeException('Credit transaction could not commit.');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Credit reservation identifier is invalid.');
        }
    }

    private function assertUnits(int $units): void
    {
        if ($units < 1 || $units > self::MAX_UNITS) {
            throw new InvalidArgumentException('Credit reservation units are invalid.');
        }
    }

    private function assertIdempotencyKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 255 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException('Credit reservation idempotency key is invalid.');
        }
    }
}
