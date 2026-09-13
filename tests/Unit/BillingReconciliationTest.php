<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Billing\{BillingRepository,GatewaySettingsService,BillingReconciliationService,HttpTransport,CouponService};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingReconciliationTest extends TestCase
{
    private PDO $pdo;private BillingRepository $repo;private GatewaySettingsService $settings;private $http;
    protected function setUp():void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$this->pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users VALUES(7,1,'a@example.test'),(8,1,'b@example.test'); INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1)");
        $this->repo=new BillingRepository($this->pdo);$this->settings=new GatewaySettingsService($this->pdo,new SecretCipher(base64_encode(str_repeat('k',32))));
        $this->settings->save('stripe','production',['secret'=>'sk_live_abcdefghijkl','webhook_secret'=>'whsec_abcdefghijkl','active'=>true],7);
        $quote=json_encode(['plan_id'=>2,'plan_name'=>'Pro','amount_cents'=>1990,'currency'=>'BRL','email'=>'a@example.test','return_base'=>'https://app.example.test']);
        $this->repo->execute("INSERT INTO billing_checkout_attempts(id,user_id,plan_id,provider,environment,request_key,quote_json,status,created_epoch) VALUES('attempt',7,2,'stripe','production','request',?,'ambiguous',?)",[$quote,time()-60]);
        $this->http=new class implements HttpTransport {
            public string $sessionStatus='open';public bool $fail=false;public array $methods=[];
            public function request(string $method,string $url,array $headers,array $body=[]):array {
                $this->methods[]=$method;if($this->fail)throw new \RuntimeException('offline');
                $session=['id'=>'cs_one','livemode'=>true,'client_reference_id'=>'attempt','metadata'=>['attempt_id'=>'attempt'],'mode'=>'subscription','currency'=>'brl','amount_total'=>1990,'status'=>$this->sessionStatus,'url'=>'https://checkout.stripe.com/c/pay/one','subscription'=>$this->sessionStatus==='complete'?'sub_one':null];
                if(str_contains($url,'checkout/sessions?'))return ['data'=>[$session],'has_more'=>false];
                if(str_contains($url,'checkout/sessions/'))return $session;
                if(str_contains($url,'subscriptions/'))return ['id'=>'sub_one','livemode'=>true,'customer'=>'cus_one','status'=>'active','metadata'=>['attempt_id'=>'attempt'],'latest_invoice'=>'in_one','items'=>['data'=>[['quantity'=>1,'current_period_start'=>time()-60,'current_period_end'=>time()+86400,'price'=>['unit_amount'=>1990,'currency'=>'brl','recurring'=>['interval'=>'month','interval_count'=>1]]]]]];
                return ['id'=>'in_one','livemode'=>true,'customer'=>'cus_one','parent'=>['subscription_details'=>['subscription'=>'sub_one']],'status'=>'paid','amount_paid'=>1990,'total'=>1990,'currency'=>'brl','status_transitions'=>['paid_at'=>time()],'lines'=>['has_more'=>false,'data'=>[['amount'=>1990,'currency'=>'brl','quantity'=>1,'parent'=>['type'=>'subscription_item_details','subscription_item_details'=>['subscription'=>'sub_one','proration'=>false]],'period'=>['start'=>time()-60,'end'=>time()+86400]]]]];
            }
        };
    }
    public function testLostCheckoutResponseIsRecoveredByReadsWithoutSecondCreation():void
    {
        $service=new BillingReconciliationService($this->repo,$this->settings,$this->http);
        self::assertSame('pending',$service->reconcile(7,'attempt')['status']);
        self::assertSame('cs_one',$this->repo->attempt('attempt')['provider_checkout_id']);
        self::assertNotContains('POST',$this->http->methods);
        $this->http->sessionStatus='complete';self::assertSame('processed',$service->reconcile(7,'attempt')['status']);
        self::assertSame(2,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
    }
    public function testProviderExpiredCheckoutReleasesReservedCoupon():void
    {
        $coupons=new CouponService($this->repo);$coupons->save(['code'=>'FIXED','discount_type'=>'fixed','discount_value'=>100,'is_active'=>true]);
        $this->repo->transaction(fn()=> $coupons->reserve('FIXED',7,2,1990,'attempt','production'));
        $this->http->sessionStatus='expired';
        self::assertSame('expired',(new BillingReconciliationService($this->repo,$this->settings,$this->http))->reconcile(7,'attempt')['status']);
        self::assertSame('released',$this->pdo->query('SELECT status FROM coupon_redemptions')->fetchColumn());
    }
    public function testReconciliationCannotReadAnotherUsersAttempt():void
    {
        $this->expectException(\DomainException::class);(new BillingReconciliationService($this->repo,$this->settings,$this->http))->reconcile(8,'attempt');
    }
}
