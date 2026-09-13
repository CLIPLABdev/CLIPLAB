<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Credits\CreditReservation;
use App\Credits\InsufficientCredits;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Services\CreditReservationService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CreditReservationServiceTest extends TestCase
{
    private PDO $pdo;
    private CreditReservationService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->createSchema();
        $this->pdo->exec("INSERT INTO users (id, credits, status) VALUES (7, 10, 'active')");
        $this->pdo->exec('INSERT INTO projects (id, user_id) VALUES (9, 7)');
        $this->pdo->exec(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description)
             VALUES (7, 'credit', 10, 10, 'registration', 'Initial credits')"
        );
        $this->service = $this->service(1);
    }

    public function testCostRoundsEachStartedMinute(): void
    {
        $service = $this->service(2);

        self::assertSame(2, $service->costForDuration(1));
        self::assertSame(2, $service->costForDuration(60));
        self::assertSame(4, $service->costForDuration(61));
    }

    public function testCostAllowsTheSignedIntegerBoundaryAndRejectsOverflow(): void
    {
        $service = $this->service(2147483647);
        self::assertSame(2147483647, $service->costForDuration(60));

        $this->expectException(InvalidArgumentException::class);
        $service->costForDuration(61);
    }

    /** @dataProvider invalidCostInputs */
    public function testCostRejectsInvalidInputs(int $creditsPerMinute, int $duration): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service($creditsPerMinute)->costForDuration($duration);
    }

    /** @return iterable<string, array{int, int}> */
    public function invalidCostInputs(): iterable
    {
        yield 'zero rate' => [0, 60];
        yield 'rate overflow' => [2147483648, 60];
        yield 'zero duration' => [1, 0];
        yield 'negative duration' => [1, -1];
    }

    public function testRepeatedReserveDebitsOnlyOnce(): void
    {
        $first = $this->service->reserve(7, 9, 2, 'analysis-9-v1');
        $second = $this->service->reserve(7, 9, 2, 'analysis-9-v1');

        self::assertSame($first->id(), $second->id());
        self::assertSame('reserved', $second->status());
        self::assertSame(8, $this->userCredits());
        self::assertSame(8, $this->latestBalance());
        self::assertSame(1, $this->reservationLedgerCount($first->id()));
    }

    public function testRepeatedConsumeAndRefundAfterConsumptionAreNoOps(): void
    {
        $reservation = $this->service->reserve(7, 9, 2, 'analysis-9-consume');

        self::assertSame('consumed', $this->service->consume($reservation->id())->status());
        self::assertSame('consumed', $this->service->consume($reservation->id())->status());
        self::assertSame('consumed', $this->service->refund($reservation->id(), 'analysis_failed')->status());
        self::assertSame(8, $this->userCredits());
        self::assertSame(8, $this->latestBalance());
        self::assertSame(1, $this->reservationLedgerCount($reservation->id()));
    }

    public function testRepeatedRefundCreditsOnlyOnce(): void
    {
        $reservation = $this->service->reserve(7, 9, 3, 'analysis-9-refund');

        self::assertSame('refunded', $this->service->refund($reservation->id(), 'analysis_failed')->status());
        self::assertSame('refunded', $this->service->refund($reservation->id(), 'analysis_failed')->status());
        self::assertSame(10, $this->userCredits());
        self::assertSame(10, $this->latestBalance());
        self::assertSame(2, $this->reservationLedgerCount($reservation->id()));
    }

    public function testConsumeDoesNotAppendLedgerOrChangeBalance(): void
    {
        $reservation = $this->service->reserve(7, 9, 4, 'analysis-9-no-second-debit');
        $before = $this->reservationLedgerCount($reservation->id());

        $consumed = $this->service->consume($reservation->id());

        self::assertSame('consumed', $consumed->status());
        self::assertSame($before, $this->reservationLedgerCount($reservation->id()));
        self::assertSame(6, $this->latestBalance());
        self::assertSame(6, $this->userCredits());
    }

    public function testLatestLedgerBalanceIsCanonicalAndResynchronizesMirror(): void
    {
        $this->pdo->exec('UPDATE users SET credits = 99 WHERE id = 7');

        $this->service->reserve(7, 9, 2, 'canonical-ledger');

        self::assertSame(8, $this->latestBalance());
        self::assertSame(8, $this->userCredits());
    }

    public function testRealZeroLedgerBalanceIsNotTreatedAsMissingHistory(): void
    {
        $this->pdo->exec('DELETE FROM credit_transactions WHERE user_id = 7');
        $this->pdo->exec('UPDATE users SET credits = 7 WHERE id = 7');
        $this->pdo->exec(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type)
             VALUES (7, 'debit', 10, 0, 'test_fixture')"
        );

        try {
            $this->service->reserve(7, 9, 1, 'zero-is-real');
            self::fail('A zero ledger balance was treated as absent.');
        } catch (InsufficientCredits $exception) {
            self::assertSame(0, $exception->available());
            self::assertSame(1, $exception->required());
        }

        self::assertSame(0, $this->reservationCount());
    }

    public function testMissingLedgerFallsBackToLockedUserMirror(): void
    {
        $this->pdo->exec('DELETE FROM credit_transactions WHERE user_id = 7');
        $this->pdo->exec('UPDATE users SET credits = 3 WHERE id = 7');

        $reservation = $this->service->reserve(7, 9, 2, 'mirror-fallback');

        self::assertSame('reserved', $reservation->status());
        self::assertSame(1, $this->latestBalance());
        self::assertSame(1, $this->userCredits());
    }

    public function testProjectMustBelongToTheGivenUser(): void
    {
        $this->pdo->exec("INSERT INTO users (id, credits, status) VALUES (8, 10, 'active')");
        $this->pdo->exec('INSERT INTO projects (id, user_id) VALUES (10, 8)');

        try {
            $this->service->reserve(7, 10, 2, 'foreign-project');
            self::fail('A foreign project was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $this->reservationCount());
        }
    }

    public function testSameIdempotencyKeyRejectsDifferentUnitsOrProject(): void
    {
        $this->pdo->exec('INSERT INTO projects (id, user_id) VALUES (10, 7)');
        $this->service->reserve(7, 9, 2, 'same-key');

        foreach ([[7, 9, 3], [7, 10, 2]] as [$userId, $projectId, $units]) {
            try {
                $this->service->reserve($userId, $projectId, $units, 'same-key');
                self::fail('A conflicting idempotency reuse was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, $this->reservationCount());
            }
        }
    }

    /** @dataProvider invalidReservationInputs */
    public function testReserveRejectsInvalidBoundariesBeforePersistence(
        int $userId,
        int $projectId,
        int $units,
        string $key
    ): void {
        try {
            $this->service->reserve($userId, $projectId, $units, $key);
            self::fail('An invalid reservation boundary was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $this->reservationCount());
        }
    }

    /** @return iterable<string, array{int, int, int, string}> */
    public function invalidReservationInputs(): iterable
    {
        yield 'zero user' => [0, 9, 1, 'valid'];
        yield 'zero project' => [7, 0, 1, 'valid'];
        yield 'zero units' => [7, 9, 0, 'valid'];
        yield 'negative units' => [7, 9, -1, 'valid'];
        yield 'units overflow' => [7, 9, 2147483648, 'valid'];
        yield 'empty key' => [7, 9, 1, ''];
        yield 'blank key' => [7, 9, 1, '   '];
        yield 'control in key' => [7, 9, 1, "bad\nkey"];
        yield 'oversized key' => [7, 9, 1, str_repeat('a', 256)];
    }

    /** @dataProvider acceptedRefundReasons */
    public function testRefundAcceptsTheExactReasonAllowlist(string $reason): void
    {
        $reservation = $this->service->reserve(7, 9, 1, 'reason-' . $reason);

        self::assertSame('refunded', $this->service->refund($reservation->id(), $reason)->status());
    }

    /** @return iterable<string, array{string}> */
    public function acceptedRefundReasons(): iterable
    {
        foreach ([
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
        ] as $reason) {
            yield $reason => [$reason];
        }
    }

    /** @dataProvider rejectedRefundReasons */
    public function testRefundRejectsReasonsOutsideTheAllowlist(string $reason): void
    {
        $reservation = $this->service->reserve(7, 9, 2, 'invalid-reason-' . md5($reason));

        try {
            $this->service->refund($reservation->id(), $reason);
            self::fail('An unsupported refund reason was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame('reserved', $this->reservationStatus($reservation->id()));
            self::assertSame(8, $this->latestBalance());
        }
    }

    /** @return iterable<string, array{string}> */
    public function rejectedRefundReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'case mismatch' => ['AI_TIMEOUT'];
        yield 'suffix' => ['ai_timeout_extra'];
        yield 'whitespace' => [' ai_timeout'];
    }

    public function testServiceLeavesCallerOwnedSuccessfulTransactionOpen(): void
    {
        $this->pdo->beginTransaction();

        $reservation = $this->service->reserve(7, 9, 2, 'caller-owned-success');

        self::assertTrue($this->pdo->inTransaction());
        self::assertSame('reserved', $reservation->status());
        $this->pdo->rollBack();
        self::assertSame(0, $this->reservationCount());
        self::assertSame(10, $this->latestBalance());
        self::assertSame(10, $this->userCredits());
    }

    public function testServiceDoesNotRollBackCallerOwnedTransactionOnFailure(): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->service->reserve(7, 999, 2, 'caller-owned-failure');
            self::fail('An unknown project was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    public function testLockedBalanceRepositoryPreservesZeroAsAValue(): void
    {
        $this->pdo->exec('DELETE FROM credit_transactions WHERE user_id = 7');
        $this->pdo->exec(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after)
             VALUES (7, 'debit', 10, 0)"
        );

        $repository = new CreditTransactionRepository($this->pdo);

        self::assertSame(0, $repository->latestLockedBalanceForUser(7));
        self::assertSame(0, $repository->latestBalanceForUser(7));
        self::assertNull($repository->latestLockedBalanceForUser(999));
    }

    public function testReservationValueObjectExposesValidatedIdentityAndState(): void
    {
        $reservation = new CreditReservation(1, 7, 9, 2, 'reserved');

        self::assertSame(1, $reservation->id());
        self::assertSame(7, $reservation->userId());
        self::assertSame(9, $reservation->projectId());
        self::assertSame(2, $reservation->units());
        self::assertSame('reserved', $reservation->status());
    }

    public function testInsufficientCreditsExposesOnlyAvailableAndRequiredAmounts(): void
    {
        $exception = new InsufficientCredits(2, 3);

        self::assertSame(2, $exception->available());
        self::assertSame(3, $exception->required());
        self::assertSame('Créditos insuficientes.', $exception->getMessage());
    }

    private function service(int $creditsPerMinute): CreditReservationService
    {
        return new CreditReservationService(
            $this->pdo,
            new CreditReservationRepository($this->pdo),
            new CreditTransactionRepository($this->pdo),
            $creditsPerMinute
        );
    }

    private function createSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    credits INTEGER NOT NULL,
    status TEXT NOT NULL
);
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id)
);
CREATE TABLE credit_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    type TEXT NOT NULL,
    amount INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    reference_type TEXT NULL,
    reference_id INTEGER NULL,
    description TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE credit_reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    project_id INTEGER NOT NULL REFERENCES projects(id),
    operation TEXT NOT NULL DEFAULT 'ai_analysis',
    units INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'reserved',
    idempotency_key TEXT NOT NULL,
    credit_transaction_id INTEGER NULL REFERENCES credit_transactions(id),
    refund_transaction_id INTEGER NULL REFERENCES credit_transactions(id),
    consumed_at TEXT NULL,
    refunded_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, idempotency_key)
);
SQL);
    }

    private function userCredits(): int
    {
        return (int) $this->pdo->query('SELECT credits FROM users WHERE id = 7')->fetchColumn();
    }

    private function latestBalance(): int
    {
        return (new CreditTransactionRepository($this->pdo))->latestBalanceForUser(7);
    }

    private function reservationCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM credit_reservations')->fetchColumn();
    }

    private function reservationLedgerCount(int $reservationId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM credit_transactions
             WHERE reference_type = 'credit_reservation' AND reference_id = ?"
        );
        $statement->execute([$reservationId]);

        return (int) $statement->fetchColumn();
    }

    private function reservationStatus(int $reservationId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM credit_reservations WHERE id = ?');
        $statement->execute([$reservationId]);

        return (string) $statement->fetchColumn();
    }
}
