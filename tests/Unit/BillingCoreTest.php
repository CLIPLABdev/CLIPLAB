<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Billing\{BillingRepository, GatewaySettingsService, CheckoutService, BillingWebhookProcessor, HttpTransport};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingCoreTest extends TestCase
{
    private PDO $pdo;
    private $settings;
    private $repo;
    private $http;
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(file_get_contents(__DIR__ . '/../Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users (id,plan_id,email) VALUES (7,1,'owner@example.test'),(8,1,'other@example.test'); INSERT INTO plans VALUES (1,'Free',0,1),(2,'Pro',1990,1),(3,'Old',5000,0)");
        $this->repo = new BillingRepository($this->pdo);
        $this->settings = new GatewaySettingsService($this->pdo, new SecretCipher(base64_encode(str_repeat('k',32))));
        $this->settings->save('stripe','production', ['secret'=>'sk_live_abcdefghijklmno','webhook_secret'=>'whsec_abcdefghijklmno','active'=>true], 7);
        $this->http = new BillingFakeHttp();
    }
    private function checkout(): CheckoutService { return new CheckoutService($this->repo,$this->settings,$this->http,'https://app.example.test'); }
    private function start(): array { return $this->checkout()->start(7,2,'stripe','production','0123456789abcdef0123456789abcdef'); }
    private function deliver(string $event='evt_one'): array
    {
        $raw=json_encode(['id'=>$event,'type'=>'checkout.session.completed','livemode'=>true,'data'=>['object'=>['id'=>'cs_test_one']]]);
        $header='t='.time().',v1='.hash_hmac('sha256',time().'.'.$raw,'whsec_abcdefghijklmno');
        return (new BillingWebhookProcessor($this->repo,$this->settings,$this->http))->process('stripe','production',$raw,$header);
    }
    public function testDoubleClickUsesOnePersistentCheckoutAndNeverGrantsAccess(): void
    {
        $one=$this->start(); $two=$this->start();
        self::assertSame($one['id'],$two['id']); self::assertSame('https://checkout.stripe.com/c/pay/one',$two['checkout_url']);
        self::assertCount(1,$this->http->posts); self::assertSame('1',(string)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        self::assertSame(1990,json_decode($one['quote_json'],true)['amount_cents']);
    }
    public function testRetryKeepsPriceAndProviderIdempotencyAfterPriceEdit(): void
    {
        $this->http->fail=true;
        try {$this->start(); self::fail('Network ambiguity must not succeed');} catch (\RuntimeException $e) {}
        $this->pdo->exec('UPDATE plans SET price_cents=9999 WHERE id=2');
        $this->http->fail=false; $result=$this->start();
        self::assertSame(1990,json_decode($result['quote_json'],true)['amount_cents']);
        self::assertSame($this->http->posts[0]['headers']['Idempotency-Key'],$this->http->posts[1]['headers']['Idempotency-Key']);
        self::assertSame($this->http->posts[0]['body'],$this->http->posts[1]['body']);
    }
    public function testAuthoritativePaidEventIsTransactionalAndReplaySafe(): void
    {
        $this->start(); self::assertSame('processed',$this->deliver()['status']);
        self::assertSame('duplicate',$this->deliver()['status']);
        self::assertSame('2',(string)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_payments')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_subscriptions')->fetchColumn());
    }
    public function testValueMismatchCannotGrantEntitlement(): void
    {
        $this->start(); $this->http->amount=1;
        self::assertSame('rejected',$this->deliver()['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_payments')->fetchColumn());
    }
    public function testDelayedFailureCannotUndoConfirmedPaidInvoice(): void
    {
        $this->start(); $this->deliver(); $this->http->paymentStatus='open';
        $this->deliver('evt_old_failure');
        self::assertSame('paid',$this->pdo->query('SELECT status FROM billing_payments')->fetchColumn());
        self::assertSame(2,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
    }
    public function testApiFailureLeavesEventRetryableWithoutAccess(): void
    {
        $this->start(); $this->http->fail=true;
        self::assertSame('retry',$this->deliver()['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        $this->http->fail=false; self::assertSame('processed',$this->deliver()['status']);
    }
    public function testSettingsNeverExposeSecretsAndBlankSavePreservesThem(): void
    {
        $this->settings->save('stripe','production',['secret'=>'','webhook_secret'=>'','active'=>true],7);
        $public=json_encode($this->settings->masked());
        self::assertStringNotContainsString('abcdefghijklmno',$public);
        self::assertStringNotContainsString('sk_live_',$this->pdo->query('SELECT secret_ciphertext FROM billing_gateway_settings')->fetchColumn());
        self::assertSame('sk_live_abcdefghijklmno',$this->settings->credentials('stripe','production')['secret']);
    }
    public function testInactivePlanRejectedBeforeProviderCall(): void
    {
        $this->expectException(\DomainException::class);
        $this->checkout()->start(7,3,'stripe','production','0123456789abcdef0123456789abcdef');
    }
    public function testConfirmedCancellationRevokesOnlyItsOwnedPlan(): void
    {
        $this->start();$this->deliver();$this->http->subscriptionStatus='canceled';$this->deliver('evt_cancel');
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        self::assertSame('canceled',$this->pdo->query('SELECT status FROM billing_subscriptions')->fetchColumn());
    }
    public function testOutboxFailureRollsBackEntitlementAndPayment(): void
    {
        $this->start();
        $emitter=new class implements \App\Contracts\CommunicationEmitter {
            public function emit(int $userId,string $event,array $variables,string $dedupeKey,?string $recipient=null,array $channels=['in_app','email'],?\DateTimeImmutable $availableAt=null): void {throw new \RuntimeException('outbox unavailable');}
            public function cancelByDedupePrefix(int $userId,string $prefix): void {}
        };
        $raw=json_encode(['id'=>'evt_atomic','type'=>'checkout.session.completed','livemode'=>true,'data'=>['object'=>['id'=>'cs_test_one']]]);
        $header='t='.time().',v1='.hash_hmac('sha256',time().'.'.$raw,'whsec_abcdefghijklmno');
        self::assertSame('retry',(new BillingWebhookProcessor($this->repo,$this->settings,$this->http,$emitter))->process('stripe','production',$raw,$header)['status']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_payments')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
    }
    public function testInvalidSignatureDoesNotPersistOrLookUpEvent(): void
    {
        $this->expectException(\DomainException::class);
        (new BillingWebhookProcessor($this->repo,$this->settings,$this->http))->process('stripe','production','{"id":"evt_forged"}','bad');
    }
    public function testReplayWithChangedPayloadIsRejected(): void
    {
        $this->start();$this->deliver();
        $raw=json_encode(['id'=>'evt_one','type'=>'invoice.paid','livemode'=>true,'data'=>['object'=>['id'=>'in_other']]]);
        $header='t='.time().',v1='.hash_hmac('sha256',time().'.'.$raw,'whsec_abcdefghijklmno');
        $this->expectException(\DomainException::class);
        (new BillingWebhookProcessor($this->repo,$this->settings,$this->http))->process('stripe','production',$raw,$header);
    }
    public function testPagarmeCrashBoundaryIsMarkedBeforeExternalCreation(): void
    {
        $this->settings->save('pagarme','production',['secret'=>'sk_live_abcdefghijklmno','webhook_secret'=>str_repeat('a',64),'active'=>true],7);
        $repo=$this->repo;
        $http=new class($this->pdo) implements HttpTransport {
            public string $observed='';
            public function __construct(private PDO $pdo){}
            public function request(string $method,string $url,array $headers,array $body=[]): array {
                $this->observed=$this->pdo->query('SELECT status FROM billing_checkout_attempts')->fetchColumn();
                throw new \RuntimeException('lost response');
            }
        };
        try {(new CheckoutService($repo,$this->settings,$http,'https://app.example.test'))->start(7,2,'pagarme','production',str_repeat('a',32));}catch(\RuntimeException $e){}
        self::assertSame('ambiguous',$http->observed);
        self::assertSame('ambiguous',$this->pdo->query('SELECT status FROM billing_checkout_attempts')->fetchColumn());
        $this->expectException(\DomainException::class);
        (new CheckoutService($repo,$this->settings,$http,'https://app.example.test'))->start(7,2,'pagarme','production',str_repeat('a',32));
    }
    public function testSandboxRecordsPaymentWithoutGrantingProductionAccess(): void
    {
        $this->start();$this->pdo->exec("UPDATE billing_checkout_attempts SET environment='sandbox'");
        $this->settings->save('stripe','sandbox',['secret'=>'sk_test_abcdefghijklmno','webhook_secret'=>'whsec_abcdefghijklmno','active'=>true],7);
        $this->http->live=false;
        $raw=json_encode(['id'=>'evt_sandbox','type'=>'checkout.session.completed','livemode'=>false,'data'=>['object'=>['id'=>'cs_test_one']]]);
        $header='t='.time().',v1='.hash_hmac('sha256',time().'.'.$raw,'whsec_abcdefghijklmno');
        self::assertSame('processed',(new BillingWebhookProcessor($this->repo,$this->settings,$this->http))->process('stripe','sandbox',$raw,$header)['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        self::assertSame('paid',$this->repo->history(7)[0]['status']);
        self::assertSame([],$this->repo->history(8));
        self::assertNull($this->repo->attemptForUser($this->pdo->query('SELECT id FROM billing_checkout_attempts')->fetchColumn(),8));
    }
    public function testLiveObjectCannotConfirmSandboxAttempt(): void
    {
        $this->start();$this->http->live=false;
        self::assertSame('rejected',$this->deliver()['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
    }
    public function testDifferentClickKeyDoesNotCreateParallelSubscription(): void
    {
        $this->start();$this->expectException(\DomainException::class);
        $this->checkout()->start(7,2,'stripe','production',str_repeat('z',32));
    }
    public function testExpiredProviderIdempotencyWindowCannotRepeatPost(): void
    {
        $this->http->fail=true;try{$this->start();}catch(\RuntimeException $e){}
        $this->pdo->exec('UPDATE billing_checkout_attempts SET created_epoch=1');
        $this->expectException(\DomainException::class);$this->start();
    }
    public function testConfirmedDiscountedPaymentConsumesReservationOnceAndKeepsGrossAmount(): void
    {
        (new \App\Billing\CouponService($this->repo))->save(['code'=>'SAVE10','discount_type'=>'percent','discount_value'=>10,'is_active'=>true,'max_redemptions'=>1,'per_user_limit'=>1,'plan_ids'=>[2]]);
        $this->checkout()->start(7,2,'stripe','production',str_repeat('c',32),'SAVE10');$this->http->amount=1791;
        self::assertSame('processed',$this->deliver()['status']);$this->deliver('evt_coupon_duplicate');
        $payment=$this->repo->history(7)[0];self::assertSame(1990,(int)$payment['gross_amount_cents']);self::assertSame(199,(int)$payment['discount_cents']);self::assertSame(1791,(int)$payment['paid_amount_cents']);
        self::assertSame('applied',$this->pdo->query('SELECT status FROM coupon_redemptions')->fetchColumn());
        self::assertSame((int)$payment['id'],(int)$this->pdo->query('SELECT payment_id FROM coupon_redemptions')->fetchColumn());
    }
    public function testPaidCallbackCannotReactivateFullyRefundedInvoice():void
    {
        $this->start();$this->deliver();$this->pdo->exec("UPDATE billing_payments SET status='refunded',refunded_amount_cents=1990; DELETE FROM billing_entitlements; UPDATE users SET plan_id=1 WHERE id=7");
        $this->deliver('evt_late_paid');self::assertSame('refunded',$this->repo->history(7)[0]['status']);self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
    }
}

final class BillingFakeHttp implements HttpTransport
{
    public array $posts=[]; public bool $fail=false; public bool $live=true; public int $amount=1990; public string $paymentStatus='paid'; public string $subscriptionStatus='active'; private string $attempt='';
    public function request(string $method,string $url,array $headers,array $body=[]): array
    {
        if ($method==='POST') { $this->posts[]=compact('url','headers','body'); $this->attempt=$body['client_reference_id']; }
        if($this->fail) throw new \RuntimeException('Provider unavailable');
        if($method==='POST') return ['id'=>'cs_test_one','url'=>'https://checkout.stripe.com/c/pay/one','livemode'=>$this->live];
        if(str_contains($url,'checkout/sessions/')) return ['id'=>'cs_test_one','livemode'=>$this->live,'client_reference_id'=>$this->attempt,'mode'=>'subscription','amount_total'=>$this->amount,'currency'=>'brl','customer'=>'cus_one','subscription'=>'sub_one','status'=>'complete','payment_status'=>'paid'];
        if(str_contains($url,'subscriptions/')) return ['id'=>'sub_one','livemode'=>$this->live,'customer'=>'cus_one','status'=>$this->subscriptionStatus,'metadata'=>['attempt_id'=>$this->attempt],'latest_invoice'=>'in_one','items'=>['data'=>[['quantity'=>1,'current_period_start'=>time()-30,'current_period_end'=>time()+86400,'price'=>['unit_amount'=>$this->amount,'currency'=>'brl','recurring'=>['interval'=>'month','interval_count'=>1]]]]]];
        return ['id'=>'in_one','livemode'=>$this->live,'customer'=>'cus_one','parent'=>['subscription_details'=>['subscription'=>'sub_one']],'status'=>$this->paymentStatus,'amount_paid'=>$this->paymentStatus==='paid'?$this->amount:0,'total'=>$this->amount,'currency'=>'brl','period_start'=>time()-30,'period_end'=>time()+86400,'status_transitions'=>['paid_at'=>time()],'lines'=>['has_more'=>false,'data'=>[['amount'=>$this->amount,'currency'=>'brl','quantity'=>1,'parent'=>['type'=>'subscription_item_details','subscription_item_details'=>['subscription'=>'sub_one','proration'=>false]],'period'=>['start'=>time()-30,'end'=>time()+86400]]]]];
    }
}
