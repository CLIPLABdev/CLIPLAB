<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Credits\InsufficientCredits;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Services\CreditReservationService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CreditReservationServiceIntegrationTest extends TestCase
{
    private PDO $pdo;
    private CreditReservationService $service;
    private int $planId;
    private int $userId;
    private int $projectId;

    /** @var list<int> */
    private array $extraPlanIds = [];

    /** @var list<int> */
    private array $extraUserIds = [];

    /** @var list<int> */
    private array $extraProjectIds = [];

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        [$this->planId, $this->userId, $this->projectId] = $this->createFixture(10);
        $this->service = $this->service();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach (array_reverse(array_merge([$this->projectId ?? 0], $this->extraProjectIds)) as $projectId) {
            if ($projectId > 0) {
                $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$projectId]);
            }
        }
        foreach (array_reverse(array_merge([$this->userId ?? 0], $this->extraUserIds)) as $userId) {
            if ($userId > 0) {
                $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            }
        }
        foreach (array_reverse(array_merge([$this->planId ?? 0], $this->extraPlanIds)) as $planId) {
            if ($planId > 0) {
                $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$planId]);
            }
        }
    }

    public function testReserveDebitsOnceAndRefundCreditsOnce(): void
    {
        $first = $this->service->reserve($this->userId, $this->projectId, 3, 'same-logical-operation');
        $second = $this->service->reserve($this->userId, $this->projectId, 3, 'same-logical-operation');

        self::assertSame($first->id(), $second->id());
        self::assertSame(7, $this->latestBalance());
        self::assertSame(7, $this->userMirror());
        self::assertSame(
            hash('sha256', $this->userId . ':same-logical-operation'),
            $this->reservationRow($first->id())['idempotency_key']
        );
        self::assertSame('ai_analysis', $this->reservationRow($first->id())['operation']);

        self::assertSame('refunded', $this->service->refund($first->id(), 'analysis_failed')->status());
        self::assertSame('refunded', $this->service->refund($first->id(), 'analysis_failed')->status());

        self::assertSame(10, $this->latestBalance());
        self::assertSame(10, $this->userMirror());
        self::assertSame(2, $this->reservationTransactionCount($first->id()));
        self::assertSame([
            ['type' => 'debit', 'amount' => 3, 'balance_after' => 7, 'reference_type' => 'credit_reservation'],
            ['type' => 'credit', 'amount' => 3, 'balance_after' => 10, 'reference_type' => 'credit_reservation'],
        ], $this->reservationTransactions($first->id()));
        $row = $this->reservationRow($first->id());
        self::assertSame('refunded', $row['status']);
        self::assertNotNull($row['credit_transaction_id']);
        self::assertNotNull($row['refund_transaction_id']);
        self::assertNotNull($row['refunded_at']);
    }

    public function testConsumeOnlyTransitionsStatusWithoutLedgerOrBalanceWrites(): void
    {
        $reservation = $this->service->reserve($this->userId, $this->projectId, 4, 'consume-once');
        $ledgerCount = $this->reservationTransactionCount($reservation->id());

        self::assertSame('consumed', $this->service->consume($reservation->id())->status());
        self::assertSame('consumed', $this->service->consume($reservation->id())->status());
        self::assertSame('consumed', $this->service->refund($reservation->id(), 'analysis_failed')->status());

        self::assertSame($ledgerCount, $this->reservationTransactionCount($reservation->id()));
        self::assertSame(6, $this->latestBalance());
        self::assertSame(6, $this->userMirror());
        $row = $this->reservationRow($reservation->id());
        self::assertSame('consumed', $row['status']);
        self::assertNotNull($row['consumed_at']);
        self::assertNull($row['refund_transaction_id']);
    }

    public function testLedgerZeroRemainsCanonicalOverANonzeroMirror(): void
    {
        $this->pdo->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('UPDATE users SET credits = 9 WHERE id = ?')->execute([$this->userId]);
        $this->pdo->prepare(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type)
             VALUES (?, 'debit', 10, 0, 'test_fixture')"
        )->execute([$this->userId]);

        try {
            $this->service->reserve($this->userId, $this->projectId, 1, 'zero-ledger');
            self::fail('The canonical zero ledger balance was ignored.');
        } catch (InsufficientCredits $exception) {
            self::assertSame(0, $exception->available());
            self::assertSame(1, $exception->required());
        }

        self::assertSame(0, $this->reservationCount());
    }

    public function testForeignProjectAndConflictingIdempotencyReusePersistNothingExtra(): void
    {
        [$planId, $otherUserId, $foreignProjectId] = $this->createFixture(10);
        $this->extraPlanIds[] = $planId;
        $this->extraUserIds[] = $otherUserId;
        $this->extraProjectIds[] = $foreignProjectId;

        try {
            $this->service->reserve($this->userId, $foreignProjectId, 1, 'foreign');
            self::fail('A project owned by another user was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $this->reservationCount());
        }

        $reservation = $this->service->reserve($this->userId, $this->projectId, 2, 'conflict');
        $ownedSecondProject = $this->createProject($this->userId);
        $this->extraProjectIds[] = $ownedSecondProject;
        foreach ([[$this->projectId, 3], [$ownedSecondProject, 2]] as [$projectId, $units]) {
            try {
                $this->service->reserve($this->userId, $projectId, $units, 'conflict');
                self::fail('A conflicting idempotency key reuse was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, $this->reservationCount());
                self::assertSame(1, $this->reservationTransactionCount($reservation->id()));
            }
        }
    }

    public function testCallerOwnsReserveCommitAndRollback(): void
    {
        $this->pdo->beginTransaction();
        $reservation = $this->service->reserve($this->userId, $this->projectId, 2, 'caller-reserve');

        self::assertTrue($this->pdo->inTransaction());
        self::assertSame('reserved', $reservation->status());
        self::assertSame(8, $this->latestBalance());

        $this->pdo->rollBack();
        self::assertSame(0, $this->reservationCount());
        self::assertSame(10, $this->latestBalance());
        self::assertSame(10, $this->userMirror());
    }

    public function testCallerOwnsConsumeAndRefundTransitions(): void
    {
        $consumeReservation = $this->service->reserve($this->userId, $this->projectId, 2, 'caller-consume');
        $refundReservation = $this->service->reserve($this->userId, $this->projectId, 3, 'caller-refund');

        $this->pdo->beginTransaction();
        self::assertSame('consumed', $this->service->consume($consumeReservation->id())->status());
        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        self::assertSame('reserved', $this->reservationRow($consumeReservation->id())['status']);

        $this->pdo->beginTransaction();
        self::assertSame('refunded', $this->service->refund($refundReservation->id(), 'ai_timeout')->status());
        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        self::assertSame('reserved', $this->reservationRow($refundReservation->id())['status']);
        self::assertSame(5, $this->latestBalance());
        self::assertSame(5, $this->userMirror());
    }

    public function testCallerOwnedFailureIsNotRolledBackByService(): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->service->reserve($this->userId, 999999999, 2, 'caller-failure');
            self::fail('An unknown project was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue($this->pdo->inTransaction());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    public function testSuspendedUserCannotReserveButExistingReservationsCanSettle(): void
    {
        $consumeReservation = $this->service->reserve($this->userId, $this->projectId, 2, 'suspended-consume');
        $refundReservation = $this->service->reserve($this->userId, $this->projectId, 3, 'suspended-refund');
        $this->pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$this->userId]);

        try {
            $this->service->reserve($this->userId, $this->projectId, 1, 'suspended-new');
            self::fail('A suspended user created a new reservation.');
        } catch (InvalidArgumentException) {
            self::assertSame(2, $this->reservationCount());
        }

        self::assertSame('consumed', $this->service->consume($consumeReservation->id())->status());
        self::assertSame('refunded', $this->service->refund($refundReservation->id(), 'ai_unavailable')->status());
        self::assertSame(8, $this->latestBalance());
        self::assertSame(8, $this->userMirror());
    }

    private function service(): CreditReservationService
    {
        return new CreditReservationService(
            $this->pdo,
            new CreditReservationRepository($this->pdo),
            new CreditTransactionRepository($this->pdo),
            1
        );
    }

    /** @return array{int, int, int} */
    private function createFixture(int $credits): array
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare(
            'INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())'
        )->execute(['credit-' . $suffix, 'Credit ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, ?)'
        )->execute(['Credit fixture', 'credit-' . $suffix . '@example.test', 'not-a-real-hash', $planId, $credits]);
        $userId = (int) $this->pdo->lastInsertId();
        $projectId = $this->createProject($userId);
        if ($credits > 0) {
            $this->pdo->prepare(
                "INSERT INTO credit_transactions
                    (user_id, type, amount, balance_after, reference_type, description)
                 VALUES (?, 'credit', ?, ?, 'registration', 'Fixture credits')"
            )->execute([$userId, $credits, $credits]);
        }

        return [$planId, $userId, $projectId];
    }

    private function createProject(int $userId): int
    {
        $this->pdo->prepare(
            "INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Credit project', 'ready', 70)"
        )->execute([$userId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function latestBalance(): int
    {
        return (new CreditTransactionRepository($this->pdo))->latestBalanceForUser($this->userId);
    }

    private function userMirror(): int
    {
        $statement = $this->pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $statement->execute([$this->userId]);

        return (int) $statement->fetchColumn();
    }

    private function reservationCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM credit_reservations WHERE user_id = ?');
        $statement->execute([$this->userId]);

        return (int) $statement->fetchColumn();
    }

    private function reservationTransactionCount(int $reservationId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM credit_transactions
             WHERE user_id = ? AND reference_type = 'credit_reservation' AND reference_id = ?"
        );
        $statement->execute([$this->userId, $reservationId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{type: string, amount: int, balance_after: int, reference_type: string}> */
    private function reservationTransactions(int $reservationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT type, amount, balance_after, reference_type FROM credit_transactions
             WHERE user_id = ? AND reference_id = ? ORDER BY id'
        );
        $statement->execute([$this->userId, $reservationId]);

        return array_map(static fn (array $row): array => [
            'type' => (string) $row['type'],
            'amount' => (int) $row['amount'],
            'balance_after' => (int) $row['balance_after'],
            'reference_type' => (string) $row['reference_type'],
        ], $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function reservationRow(int $reservationId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM credit_reservations WHERE id = ?');
        $statement->execute([$reservationId]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return $row;
    }
}
