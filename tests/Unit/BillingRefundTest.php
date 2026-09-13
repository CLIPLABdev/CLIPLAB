<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Billing\{BillingRepository,GatewaySettingsService,BillingWebhookProcessor,HttpTransport};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingRefundTest extends TestCase
{
    private PDO $pdo;private BillingRepository $repo;private GatewaySettingsService $settings;private $http;
    protected function setUp():void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$this->pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users VALUES(7,2,'a@example.test'); INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1)");
        $this->repo=new BillingRepository($this->pdo);$this->settings=new GatewaySettingsService($this->pdo,new SecretCipher(base64_encode(str_repeat('k',32))));
        foreach(['stripe','pagarme'] as $provider)$this->settings->save($provider,'production',['secret'=>'sk_live_abcdefghijkl','webhook_secret'=>$provider==='stripe'?'whsec_abcdefghijkl':str_repeat('a',64),'active'=>true],7);
        $this->pdo->exec("INSERT INTO billing_subscriptions(id,user_id,plan_id,provider,environment,provider_subscription_id,provider_customer_id,status) VALUES(1,7,2,'stripe','production','sub_one','cus_one','active')");
        $this->repo->execute("INSERT INTO billing_payments(id,user_id,subscription_id,plan_id,provider,environment,provider_payment_id,provider_invoice_id,status,currency,gross_amount_cents,paid_amount_cents,period_ends_at) VALUES(1,7,1,2,'stripe','production','in_one','in_one','paid','BRL',1990,1990,?)",[gmdate('Y-m-d H:i:s',time()+86400)]);
        $this->repo->execute('INSERT INTO billing_entitlements VALUES(7,1,2,?)',[gmdate('Y-m-d H:i:s',time()+86400)]);
        $this->http=new class implements HttpTransport {
            public int $refund=500;public bool $succeeded=true;public string $customer='cus_one';public array $methods=[];
            public function request(string $method,string $url,array $headers,array $body=[]):array {
                $this->methods[]=$method;
                if(str_contains($url,'invoice_payments'))return ['data'=>[['invoice'=>'in_one','livemode'=>true,'amount_paid'=>1990,'currency'=>'brl','status'=>'paid','payment'=>['type'=>'payment_intent','payment_intent'=>'pi_one']]],'has_more'=>false];
                if(str_contains($url,'/refunds?'))return ['data'=>[['id'=>'re_one','charge'=>'ch_one','amount'=>$this->refund,'currency'=>'brl','status'=>$this->succeeded?'succeeded':'pending']],'has_more'=>false];
                if(str_contains($url,'pagar.me'))return ['id'=>'ch_one','amount'=>1990,'paid_amount'=>1990,'canceled_amount'=>$this->refund,'status'=>$this->refund===1990?'canceled':'paid','currency'=>'BRL','customer'=>['id'=>$this->customer],'invoice'=>['id'=>'in_one'],'last_transaction'=>['status'=>'refunded','success'=>$this->succeeded]];
                return ['id'=>'ch_one','livemode'=>true,'amount'=>1990,'amount_captured'=>1990,'amount_refunded'=>$this->refund,'currency'=>'brl','customer'=>$this->customer,'payment_intent'=>'pi_one','paid'=>true];
            }
        };
    }
    private function deliver(string $id='evt_refund',string $provider='stripe'):array
    {
        $raw=json_encode(['id'=>$id,'type'=>'charge.refunded','livemode'=>true,'data'=>$provider==='stripe'?['object'=>['id'=>'ch_one']]:['id'=>'ch_one']]);$now=time();$auth=$provider==='stripe'?'t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$raw,'whsec_abcdefghijkl'):str_repeat('a',64);
        return (new BillingWebhookProcessor($this->repo,$this->settings,$this->http))->process($provider,'production',$raw,$auth);
    }
    public function testPartialThenFullRefundIsAuthoritativeIdempotentAndNeverPosts():void
    {
        self::assertSame('processed',$this->deliver()['status']);self::assertSame('duplicate',$this->deliver()['status']);
        self::assertSame(500,(int)$this->repo->history(7)[0]['refunded_amount_cents']);self::assertSame('paid',$this->repo->history(7)[0]['status']);
        self::assertSame(2,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());
        $this->http->refund=1990;$this->deliver('evt_full');self::assertSame('refunded',$this->repo->history(7)[0]['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());self::assertNotContains('POST',$this->http->methods);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_refunds')->fetchColumn());
    }
    public function testPendingRefundDoesNotInventRefundedMoney():void
    {
        $this->http->succeeded=false;$this->deliver();self::assertSame(0,(int)$this->repo->history(7)[0]['refunded_amount_cents']);
    }
    public function testRefundOfOldPeriodDoesNotRevokeLaterPaidPeriod():void
    {
        $this->pdo->exec("UPDATE billing_payments SET period_ends_at='2020-01-01 00:00:00'");$this->http->refund=1990;$this->deliver();
        self::assertSame(2,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());
    }
    public function testDifferentCustomerCannotRefundLocalInvoice():void
    {
        $this->http->customer='cus_other';self::assertSame('rejected',$this->deliver()['status']);
        self::assertSame(0,(int)$this->repo->history(7)[0]['refunded_amount_cents']);
    }
    public function testPagarmeCanceledAmountIsReadAndAccountedWithoutLegacyHmac():void
    {
        $this->pdo->exec("UPDATE billing_payments SET provider='pagarme';UPDATE billing_subscriptions SET provider='pagarme'");
        self::assertSame('processed',$this->deliver('hook_refund','pagarme')['status']);self::assertSame(500,(int)$this->repo->history(7)[0]['refunded_amount_cents']);
        $this->http->refund=1990;$this->deliver('hook_full','pagarme');self::assertSame('refunded',$this->repo->history(7)[0]['status']);
    }
}
