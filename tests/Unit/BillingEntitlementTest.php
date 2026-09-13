<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Billing\{BillingRepository,BillingEntitlementService};
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingEntitlementTest extends TestCase
{
    private PDO $pdo;private BillingRepository $repo;
    protected function setUp():void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$this->pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users VALUES(7,3,'a@example.test');INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1),(3,'Business',4990,1)");$this->repo=new BillingRepository($this->pdo);
        $this->repo->execute('INSERT INTO billing_entitlements VALUES(7,10,3,?)',[gmdate('Y-m-d H:i:s',time()+86400)]);
    }
    public function testDelayedSubscriptionCannotReplaceDifferentConfirmedSource():void
    {
        $this->repo->transaction(fn()=> (new BillingEntitlementService($this->repo))->grant(7,11,2,time()+86400));
        self::assertSame(3,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());self::assertSame(10,(int)$this->pdo->query('SELECT subscription_id FROM billing_entitlements')->fetchColumn());
    }
    public function testRevokeRequiresExactSubscriptionSource():void
    {
        $this->repo->transaction(fn()=> (new BillingEntitlementService($this->repo))->revoke(7,11));self::assertSame(3,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());
    }
    public function testConfirmedPeriodExpiryRevertsOnlyBillingOwnedPlan():void
    {
        $this->pdo->exec("UPDATE billing_entitlements SET valid_until='2020-01-01 00:00:00'");(new BillingEntitlementService($this->repo))->expire(7);
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_entitlements')->fetchColumn());
    }
}
