<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Billing\{BillingRepository,CouponService,PriceQuote,CheckoutService,GatewaySettingsService,HttpTransport};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingCouponTest extends TestCase
{
    private PDO $pdo; private BillingRepository $repo;
    protected function setUp(): void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users VALUES(7,1,'a@example.test'),(8,1,'b@example.test'); INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1),(3,'Business',4990,1)");
        $this->repo=new BillingRepository($this->pdo);
    }
    private function service(): CouponService {return new CouponService($this->repo);}
    private function coupon(array $overrides=[]): int {return $this->service()->save(array_replace(['code'=>'PROMO10','discount_type'=>'percent','discount_value'=>10,'currency'=>'BRL','is_active'=>true,'max_redemptions'=>1,'per_user_limit'=>1,'plan_ids'=>[2]],$overrides));}
    private function attempt(string $id,int $user=7): void {$this->repo->execute("INSERT INTO billing_checkout_attempts(id,user_id,plan_id,provider,environment,request_key,quote_json,status,created_epoch) VALUES(?,?,2,'stripe','production',?,'{}','creating',?)",[$id,$user,str_repeat($id,32),time()]);}
    public function testDiscountArithmeticUsesIntegerCents(): void
    {
        self::assertSame(1791,PriceQuote::discount(1990,'percent',10)['amount_cents']);
        self::assertSame(199,PriceQuote::discount(1990,'percent',10)['discount_cents']);
        self::assertSame(1490,PriceQuote::discount(1990,'fixed',500)['amount_cents']);
    }
    public function testReserveIsIdempotentAndBlocksLastSlotForOtherUser(): void
    {
        $this->coupon();$this->attempt('a');$this->attempt('b',8);
        $one=$this->repo->transaction(fn()=> $this->service()->reserve('PROMO10',7,2,1990,'a','production'));
        $two=$this->repo->transaction(fn()=> $this->service()->reserve('promo10',7,2,1990,'a','production'));
        self::assertSame($one,$two);self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM coupon_redemptions')->fetchColumn());
        $this->expectException(\DomainException::class);
        $this->repo->transaction(fn()=> $this->service()->reserve('PROMO10',8,2,1990,'b','production'));
    }
    public function testReservationAppliesOnceAndReleaseDoesNotRestoreConsumedSlot(): void
    {
        $this->coupon();$this->attempt('a');$this->repo->transaction(fn()=> $this->service()->reserve('PROMO10',7,2,1990,'a','production'));
        $this->repo->transaction(function(){ $this->service()->apply('a',11);$this->service()->apply('a',11);$this->service()->release('a'); });
        self::assertSame('applied',$this->pdo->query('SELECT status FROM coupon_redemptions')->fetchColumn());
        self::assertSame(11,(int)$this->pdo->query('SELECT payment_id FROM coupon_redemptions')->fetchColumn());
    }
    public function testAuthoritativeReleaseFreesSlotButReleasedAttemptCannotApply(): void
    {
        $this->coupon();$this->attempt('a');$this->attempt('b',8);
        $this->repo->transaction(function(){ $this->service()->reserve('PROMO10',7,2,1990,'a','production');$this->service()->release('a'); });
        self::assertSame(1791,$this->repo->transaction(fn()=> $this->service()->reserve('PROMO10',8,2,1990,'b','production'))['amount_cents']);
        $this->expectException(\DomainException::class);$this->repo->transaction(fn()=> $this->service()->apply('a',12));
    }
    public function testCouponValidationRejectsExpiredDisabledWrongPlanAndUserLimit(): void
    {
        $id=$this->coupon(['max_redemptions'=>10]);$this->attempt('a');
        foreach([['is_active'=>false],['ends_at'=>'2020-01-01 00:00:00'],['starts_at'=>'2099-01-01 00:00:00'],['plan_ids'=>[3]]] as $change){
            $this->service()->save(array_replace(['code'=>'PROMO10','discount_type'=>'percent','discount_value'=>10,'currency'=>'BRL','is_active'=>true,'max_redemptions'=>10,'per_user_limit'=>1,'plan_ids'=>[2]],$change),$id);
            try{$this->repo->transaction(fn()=> $this->service()->reserve('PROMO10',7,2,1990,'a','production'));self::fail('Invalid coupon accepted');}catch(\DomainException $e){self::assertNotEmpty($e->getMessage());}
        }
        $this->service()->save(['code'=>'PROMO10','discount_type'=>'percent','discount_value'=>10,'currency'=>'BRL','is_active'=>true,'max_redemptions'=>10,'per_user_limit'=>1,'plan_ids'=>[2]],$id);
        $this->repo->transaction(fn()=> $this->service()->reserve('PROMO10',7,2,1990,'a','production'));$this->attempt('c');
        $this->expectException(\DomainException::class);$this->repo->transaction(fn()=> $this->service()->reserve('PROMO10',7,2,1990,'c','production'));
    }
    public function testCheckoutSendsDiscountedPriceAndKeepsSnapshotAfterCouponEdit(): void
    {
        $id=$this->coupon();$settings=new GatewaySettingsService($this->pdo,new SecretCipher(base64_encode(str_repeat('k',32))));
        $settings->save('stripe','production',['secret'=>'sk_live_abcdefghijkl','webhook_secret'=>'whsec_abcdefghijkl','active'=>true],7);
        $http=new class implements HttpTransport {public array $body=[];public function request(string $method,string $url,array $headers,array $body=[]):array{$this->body=$body;return ['id'=>'cs_one','url'=>'https://checkout.stripe.com/c/pay/one','livemode'=>true];}};
        $checkout=new CheckoutService($this->repo,$settings,$http,'https://app.example.test');
        $attempt=$checkout->start(7,2,'stripe','production',str_repeat('a',32),'PROMO10');
        self::assertSame(1791,$http->body['line_items'][0]['price_data']['unit_amount']);
        self::assertSame(199,json_decode($attempt['quote_json'],true)['discount_cents']);
        $this->pdo->exec('UPDATE coupons SET discount_value=20');
        self::assertSame($attempt['quote_json'],$checkout->start(7,2,'stripe','production',str_repeat('a',32),'PROMO10')['quote_json']);
    }
    public function testConcurrentReservationsCannotCommitBeyondLastSlot():void
    {
        $path=tempnam(sys_get_temp_dir(),'billing-coupon-');$first=null;$second=null;$repoA=null;$repoB=null;$a=null;$b=null;
        try{
            $first=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$second=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $first->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));$first->exec('PRAGMA busy_timeout=0');$second->exec('PRAGMA busy_timeout=0');
            $first->exec("INSERT INTO coupons(id,code,discount_type,discount_value,currency,max_redemptions,per_user_limit,is_active) VALUES(1,'LAST','percent',10,'BRL',1,1,1); INSERT INTO billing_checkout_attempts(id,user_id,plan_id,environment) VALUES('a',7,2,'production'),('b',8,2,'production')");
            $repoA=new BillingRepository($first);$repoB=new BillingRepository($second);$a=new CouponService($repoA);$b=new CouponService($repoB);
            $first->beginTransaction();$a->reserve('LAST',7,2,1990,'a','production');
            try{$repoB->transaction(fn()=> $b->reserve('LAST',8,2,1990,'b','production'));self::fail('Concurrent last-slot reservation committed');}catch(\PDOException $e){self::assertTrue($first->inTransaction());}
            $first->commit();
            try{$repoB->transaction(fn()=> $b->reserve('LAST',8,2,1990,'b','production'));self::fail('Retry exceeded coupon capacity');}catch(\DomainException $e){self::assertSame(1,(int)$first->query('SELECT COUNT(*) FROM coupon_redemptions')->fetchColumn());}
        }finally{
            if($first && $first->inTransaction())$first->rollBack();if($second && $second->inTransaction())$second->rollBack();unset($e);$a=$b=$repoA=$repoB=$first=$second=null;gc_collect_cycles();if(is_file($path))unlink($path);
        }
    }
}
