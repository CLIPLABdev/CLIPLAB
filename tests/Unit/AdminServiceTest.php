<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\AdminRepository;
use App\Repositories\SystemLogRepository;
use App\Services\AdminService;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

final class AdminServiceTest extends TestCase
{
    private PDO $pdo;
    /** @var array{plan_id:int,admin_id:int,user_id:int,other_admin_id:int} */
    private array $ids;
    private AdminService $service;

    protected function setUp(): void
    {
        $this->pdo = AdminTestDatabase::create();
        $this->ids = AdminTestDatabase::seed($this->pdo);
        $repository = new AdminRepository($this->pdo);
        $this->service = new AdminService($this->pdo, $repository, new SystemLogRepository($this->pdo));
    }

    public function testCreditAdjustmentUpdatesSnapshotLedgerAndAuditAtomically(): void
    {
        $balance = $this->service->adjustCredits($this->ids['admin_id'], $this->ids['user_id'], 7, 'Compensação aprovada');

        self::assertSame(17, $balance);
        self::assertSame(17, (int) $this->pdo->query('SELECT credits FROM users WHERE id = ' . $this->ids['user_id'])->fetchColumn());
        $entry = $this->pdo->query('SELECT type, amount, balance_after, description FROM credit_transactions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('adjustment', $entry['type']);
        self::assertSame(7, (int) $entry['amount']);
        self::assertSame(17, (int) $entry['balance_after']);
        self::assertSame('Compensação aprovada', $entry['description']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM system_logs WHERE event_code = 'admin.credits_adjusted'")->fetchColumn());
    }

    public function testProfileEditHasAccurateAuditEventAndNoPasswordPayload(): void
    {
        $this->service->editUser($this->ids['admin_id'],$this->ids['user_id'],['name'=>'Nome atualizado','email'=>'updated@example.test','role'=>'user','reason'=>'Correção autorizada']);
        $event=$this->pdo->query('SELECT event_code,public_message,context_json FROM system_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('admin.user_updated',$event['event_code']);
        self::assertStringNotContainsString('promovida',$event['public_message']);
        self::assertStringNotContainsString('updated@example.test',$event['context_json']);
    }

    public function testCreatedUsersRejectOverlongPasswordWithoutPartialAccount(): void
    {
        $before=(int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->service->createUser($this->ids['admin_id'],['name'=>'Nova conta','email'=>'new@example.test','role'=>'user','password'=>str_repeat('x',73),'plan_id'=>$this->ids['plan_id'],'reason'=>'Criação autorizada']);
        } finally { self::assertSame($before,(int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()); }
    }

    public function testNegativeBalanceAndOverflowAreRejectedWithoutPartialWrites(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn();

        foreach ([-11, PHP_INT_MAX] as $amount) {
            try {
                $this->service->adjustCredits($this->ids['admin_id'], $this->ids['user_id'], $amount, 'Tentativa inválida');
                self::fail('Invalid credit adjustment was accepted.');
            } catch (DomainException) {
                self::assertSame(10, (int) $this->pdo->query('SELECT credits FROM users WHERE id = ' . $this->ids['user_id'])->fetchColumn());
            }
        }

        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM system_logs')->fetchColumn());
    }

    public function testStatusMutationPreventsSelfSuspensionAndAuditsTarget(): void
    {
        $this->expectException(DomainException::class);
        try {
            $this->service->changeUserStatus($this->ids['admin_id'], $this->ids['admin_id'], 'suspended', 'Não permitido');
        } finally {
            self::assertSame('active', $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->ids['admin_id'])->fetchColumn());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM system_logs')->fetchColumn());
        }
    }

    public function testLastActiveAdministratorCannotBeSuspended(): void
    {
        $this->pdo->exec("UPDATE users SET status = 'suspended' WHERE id = " . $this->ids['other_admin_id']);

        try {
            $this->service->changeUserStatus($this->ids['admin_id'], $this->ids['admin_id'], 'suspended', 'Tentativa bloqueada');
            self::fail('The last active administrator was suspended.');
        } catch (DomainException $exception) {
            self::assertSame('The last active administrator cannot be suspended.', $exception->getMessage());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->ids['admin_id'])->fetchColumn());
    }

    public function testPlanAssignmentRequiresActivePlanAndPlanUpdateUsesSharedFeatureValidator(): void
    {
        $features = '{"exports_hd":true,"priority_processing":true,"team_access":false,"limits":{"max_upload_bytes":524288000,"storage_bytes":21474836480}}';
        $this->pdo->prepare('INSERT INTO plans (slug, name, price_cents, monthly_minutes, credits, features, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['pro', 'Pro', 4900, 300, 100, $features, 1]);
        $planId = (int) $this->pdo->lastInsertId();

        $this->service->assignPlan($this->ids['admin_id'], $this->ids['user_id'], $planId, 'Upgrade solicitado');
        self::assertSame($planId, (int) $this->pdo->query('SELECT plan_id FROM users WHERE id = ' . $this->ids['user_id'])->fetchColumn());

        $this->service->updatePlan($this->ids['admin_id'], $planId, [
            'name' => 'Pro Plus', 'price_cents' => 6900, 'monthly_minutes' => 450, 'credits' => 180, 'is_active' => true,
            'features' => [
                'exports_hd' => true, 'priority_processing' => true, 'team_access' => false,
                'limits' => ['max_upload_bytes' => 524288000, 'storage_bytes' => 21474836480],
            ],
        ]);
        self::assertSame('Pro Plus', $this->pdo->query('SELECT name FROM plans WHERE id = ' . $planId)->fetchColumn());

        $invalid = [
            'name' => 'Unsafe', 'price_cents' => 1, 'monthly_minutes' => 1, 'credits' => 1, 'is_active' => true,
            'features' => ['exports_hd' => true, 'priority_processing' => false, 'team_access' => false, 'unknown' => true],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->service->updatePlan($this->ids['admin_id'], $planId, $invalid);
    }
}
