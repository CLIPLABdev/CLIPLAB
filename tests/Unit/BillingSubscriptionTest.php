<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Billing\{BillingRepository,GatewaySettingsService,SubscriptionService,HttpTransport};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingSubscriptionTest extends TestCase
{
    private PDO $pdo;private BillingRepository $repo;private GatewaySettingsService $settings;private $http;
    protected function setUp(): void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$this->pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users VALUES(7,2,'a@example.test'),(8,1,'b@example.test'); INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1),(3,'Business',4990,1)");
        $this->repo=new BillingRepository($this->pdo);$this->settings=new GatewaySettingsService($this->pdo,new SecretCipher(base64_encode(str_repeat('k',32))));
        $this->settings->save('stripe','production',['secret'=>'sk_live_abcdefghijkl','webhook_secret'=>'whsec_abcdefghijkl','active'=>true],7);
        $this->repo->execute("INSERT INTO billing_checkout_attempts(id,user_id,plan_id,provider,environment,quote_json,status) VALUES('attempt',7,2,'stripe','production','{}','confirmed')");
        $this->repo->execute("INSERT INTO billing_subscriptions(id,user_id,plan_id,provider,environment,provider_subscription_id,provider_customer_id,checkout_attempt_id,status,amount_cents,currency,plan_snapshot) VALUES(1,7,2,'stripe','production','sub_one','cus_one','attempt','active',1990,'BRL','{}')");
        $this->repo->execute("INSERT INTO billing_entitlements(user_id,subscription_id,plan_id,valid_until) VALUES(7,1,2,?)",[gmdate('Y-m-d H:i:s',time()+86400)]);
        $this->http=new class implements HttpTransport {
            public bool $fail=false;public bool $confirm=true;public bool $atEnd=false;public string $status='active';public int $mutations=0;public array $lastBody=[];
            public function request(string $method,string $url,array $headers,array $body=[]):array {
                if($method!=='GET'){$this->mutations++;$this->lastBody=$body;if($this->fail)throw new \RuntimeException('unavailable');if($this->confirm){if($method==='DELETE')$this->status='canceled';else $this->atEnd=true;}}
                return ['id'=>'sub_one','livemode'=>true,'customer'=>'cus_one','metadata'=>['attempt_id'=>'attempt'],'status'=>$this->status,'cancel_at_period_end'=>$this->atEnd,'canceled_at'=>$this->status==='canceled'?time():null,'items'=>['data'=>[['current_period_start'=>time()-100,'current_period_end'=>time()+86400]]]];
            }
        };
    }
    private function service(): SubscriptionService {return new SubscriptionService($this->repo,$this->settings,$this->http);}
    public function testSchedulesStripeCancellationOnlyAfterAuthoritativeConfirmation(): void
    {
        $result=$this->service()->cancel(7,1,true);
        self::assertSame(1,(int)$result['cancel_at_period_end']);self::assertSame('active',$result['status']);
        self::assertSame(2,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        $this->service()->cancel(7,1,true);self::assertSame(1,$this->http->mutations);
    }
    public function testLostOrUnconfirmedCancellationDoesNotRevokePlan(): void
    {
        $this->http->confirm=false;
        try{$this->service()->cancel(7,1,false);self::fail('Unconfirmed cancellation accepted');}catch(\RuntimeException $e){}
        self::assertSame('active',$this->repo->subscriptions(7)[0]['status']);self::assertSame(2,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        $this->http->fail=true;$this->expectException(\RuntimeException::class);$this->service()->cancel(7,1,false);
    }
    public function testImmediateCancellationIsIdempotentAndRevokesOwnedPlan(): void
    {
        $result=$this->service()->cancel(7,1,false);$this->service()->cancel(7,1,false);
        self::assertSame('canceled',$result['status']);self::assertNotNull($result['canceled_at']);self::assertSame(1,$this->http->mutations);
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
    }
    public function testCancellationCannotAffectAnotherOwner(): void
    {
        try{$this->service()->cancel(8,1,false);self::fail('Other user canceled subscription');}catch(\DomainException $e){}
        self::assertSame(0,$this->http->mutations);
    }
    public function testCancellationDoesNotOverwriteDifferentCurrentPlan(): void
    {
        $this->pdo->exec('UPDATE users SET plan_id=3 WHERE id=7');$this->service()->cancel(7,1,false);
        self::assertSame(3,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
    }
    public function testInactiveGatewayStillAllowsExistingSubscriptionCancellation(): void
    {
        $this->settings->save('stripe','production',['active'=>false],7);
        self::assertSame('canceled',$this->service()->cancel(7,1,false)['status']);
    }
    public function testPagarmeCancellationUsesDocumentedDeleteAndConfirmsRemoteStatus():void
    {
        $this->settings->save('pagarme','production',['secret'=>'sk_live_abcdefghijkl','webhook_secret'=>str_repeat('a',64),'active'=>true],7);
        $this->pdo->exec("UPDATE billing_subscriptions SET provider='pagarme'; UPDATE billing_checkout_attempts SET provider='pagarme',provider_plan_id='plan_one'");
        $http=new class implements HttpTransport {
            public string $status='active';public array $mutations=[];
            public function request(string $method,string $url,array $headers,array $body=[]):array {
                if($method!=='GET'){$this->mutations[]=compact('method','body');$this->status='canceled';}
                return ['id'=>'sub_one','plan'=>['id'=>'plan_one'],'customer'=>['id'=>'cus_one'],'status'=>$this->status,'canceled_at'=>$this->status==='canceled'?gmdate('c'):null];
            }
        };
        $service=new SubscriptionService($this->repo,$this->settings,$http);
        self::assertSame('canceled',$service->cancel(7,1,false)['status']);self::assertSame('DELETE',$http->mutations[0]['method']);self::assertSame(['cancel_pending_invoices'=>false],$http->mutations[0]['body']);
        $service->cancel(7,1,false);self::assertCount(1,$http->mutations);
    }
    public function testOlderRefreshCannotOverwriteMoreRecentConfirmation():void
    {
        $this->pdo->prepare("UPDATE billing_subscriptions SET status='canceled',last_confirmed_epoch=? WHERE id=1")->execute([(int)floor(microtime(true)*1000000)+5000000]);
        self::assertSame('canceled',$this->service()->refresh(7,1)['status']);
    }
}
