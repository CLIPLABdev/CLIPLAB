<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Billing\FinancialRepository;
use PDO;
use PHPUnit\Framework\TestCase;
final class FinancialReportingIsolationTest extends TestCase
{
    private PDO $pdo;
    protected function setUp():void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(file_get_contents(dirname(__DIR__).'/Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users(id,email,plan_id) VALUES(1,'ana@example.test',1),(2,'bia@example.test',1)");
        $this->pdo->exec("INSERT INTO plans VALUES(1,'Pro',10000,1)");
        $statement=$this->pdo->prepare("INSERT INTO billing_payments(user_id,plan_id,provider,environment,provider_payment_id,status,currency,gross_amount_cents,paid_amount_cents,refunded_amount_cents,paid_at) VALUES(1,1,'stripe',?,?, 'paid',?,10000,10000,2000,?)");
        foreach([['production','one','BRL','2026-09-07 12:00:00'],['sandbox','two','BRL','2026-09-07 12:00:00'],['production','three','USD','2026-09-07 12:00:00'],['production','four','BRL','2026-08-01 12:00:00']] as $row)$statement->execute($row);
    }
    public function testDefaultsExcludeSandboxAndNeverSumDifferentCurrencies():void
    {
        $report=(new FinancialRepository($this->pdo))->report([]);
        self::assertSame(2,(int)$report['totals']['count']);self::assertSame(16000,(int)$report['totals']['paid_cents']);
        self::assertSame('production',$report['filters']['environment']);self::assertSame('BRL',$report['filters']['currency']);
        $sandbox=(new FinancialRepository($this->pdo))->report(['environment'=>'sandbox']);self::assertSame(1,(int)$sandbox['totals']['count']);
        $usd=(new FinancialRepository($this->pdo))->report(['currency'=>'USD']);self::assertSame(1,(int)$usd['totals']['count']);
    }
    public function testDashboardUsesNetProductionReceiptsAndUtcCalendarMonth():void
    {
        $summary=(new FinancialRepository($this->pdo))->dashboard(null,new \DateTimeImmutable('2026-09-07 12:00:00 UTC'));
        self::assertSame(16000,$summary['net_total_cents']);self::assertSame(8000,$summary['net_month_cents']);
        self::assertSame(0,$summary['subscriptions_active']);
        self::assertSame(0,(new FinancialRepository($this->pdo))->dashboard(2)['net_total_cents']);
    }
}
