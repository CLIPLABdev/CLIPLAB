<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\AccountRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AccountRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, price_cents INTEGER, monthly_minutes INTEGER, credits INTEGER, features TEXT, is_active INTEGER)');
        $this->pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT, amount INTEGER, balance_after INTEGER, reference_type TEXT NULL, reference_id INTEGER NULL, description TEXT NULL, created_at TEXT)');

        $free = json_encode(['exports_hd' => false, 'priority_processing' => false], JSON_THROW_ON_ERROR);
        $pro = json_encode(['exports_hd' => true, 'priority_processing' => true, 'team_access' => false, 'limits' => ['max_upload_bytes' => 500, 'storage_bytes' => 2000]], JSON_THROW_ON_ERROR);
        $insert = $this->pdo->prepare('INSERT INTO plans VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'free', 'Free', 0, 30, 10, $free, 1]);
        $insert->execute([2, 'pro', 'Pro', 1990, 300, 150, $pro, 1]);
        $insert->execute([3, 'old', 'Old', 99, 1, 1, $free, 0]);
        $this->repository = new AccountRepository($this->pdo);
    }

    public function testListsOnlyActivePlansWithNormalizedFeatures(): void
    {
        $plans = $this->repository->activePlans();

        self::assertSame(['free', 'pro'], array_column($plans, 'slug'));
        self::assertSame(104857600, $plans[0]['features']['limits']['max_upload_bytes']);
        self::assertSame(2000, $plans[1]['features']['limits']['storage_bytes']);
        self::assertSame(150, $plans[1]['credits']);
    }

    public function testLedgerIsOwnerScopedNewestFirstAndPaginatesByTwentyFive(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO credit_transactions VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($id = 1; $id <= 27; $id++) {
            $insert->execute([$id, 7, 'debit', -1, 30 - $id, 'credit_reservation', $id, 'Consumo', sprintf('2026-09-%02d 10:00:00', $id)]);
        }
        $insert->execute([100, 8, 'credit', 999, 999, 'admin_adjustment', 1, 'Privado', '2026-09-30 10:00:00']);

        $first = $this->repository->ledgerForUser(7, 'all', 1);
        $second = $this->repository->ledgerForUser(7, 'consumption', 2);

        self::assertSame(27, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertCount(25, $first['items']);
        self::assertSame(27, $first['items'][0]['id']);
        self::assertSame('consumption', $first['items'][0]['kind']);
        self::assertCount(2, $second['items']);
        self::assertNotContains(100, array_column($first['items'], 'id'));
    }

    public function testLedgerSemanticFiltersDistinguishRefundsAdditionsAndAdjustments(): void
    {
        $this->pdo->exec("INSERT INTO credit_transactions VALUES
            (1, 7, 'credit', 10, 10, 'signup_bonus', NULL, 'Bônus', '2026-09-01 10:00:00'),
            (2, 7, 'credit', 2, 12, 'credit_reservation', 9, 'Reembolso', '2026-09-02 10:00:00'),
            (3, 7, 'adjustment', -1, 11, 'admin_adjustment', 4, 'Ajuste', '2026-09-03 10:00:00'),
            (4, 7, 'debit', -2, 9, 'credit_reservation', 10, 'Consumo', '2026-09-04 10:00:00')");

        self::assertSame([1], array_column($this->repository->ledgerForUser(7, 'additions', 1)['items'], 'id'));
        self::assertSame([2], array_column($this->repository->ledgerForUser(7, 'refunds', 1)['items'], 'id'));
        self::assertSame([3], array_column($this->repository->ledgerForUser(7, 'adjustments', 1)['items'], 'id'));
        self::assertSame([4], array_column($this->repository->ledgerForUser(7, 'consumption', 1)['items'], 'id'));
    }

    public function testRejectsNonAllowlistedFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->ledgerForUser(7, 'anything', 1);
    }
}
