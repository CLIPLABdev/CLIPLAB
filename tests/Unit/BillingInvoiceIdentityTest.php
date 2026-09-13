<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Billing\{BillingRepository,GatewaySettingsService,CheckoutService,BillingWebhookProcessor,HttpTransport};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingInvoiceIdentityTest extends TestCase
{
    private PDO $pdo;
    private BillingWebhookProcessor $processor;
    private InvoiceIdentityHttp $http;
    protected function setUp():void
    {
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $this->pdo->exec("INSERT INTO users VALUES(7,1,'owner@example.test'); INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1)");
        $repo=new BillingRepository($this->pdo);
        $settings=new GatewaySettingsService($this->pdo,new SecretCipher(base64_encode(str_repeat('k',32))));
        $settings->save('stripe','production',['secret'=>'sk_live_abcdefghijklmno','webhook_secret'=>'whsec_abcdefghijklmno','active'=>true],7);
        $this->http=new InvoiceIdentityHttp();
        (new CheckoutService($repo,$settings,$this->http,'https://app.example.test'))->start(7,2,'stripe','production',str_repeat('a',32));
        $this->processor=new BillingWebhookProcessor($repo,$settings,$this->http);
    }
    private function deliver(string $event='evt_old'):array
    {
        $raw=json_encode(['id'=>$event,'type'=>'invoice.paid','livemode'=>true,'data'=>['object'=>['id'=>$this->http->invoiceId]]],JSON_THROW_ON_ERROR);
        $now=time();return $this->processor->process('stripe','production',$raw,'t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$raw,'whsec_abcdefghijklmno'));
    }
    public function testDelayedInvoiceRecordsItsOwnIdentityAndPaidServicePeriod():void
    {
        self::assertSame('processed',$this->deliver()['status']);
        $payment=$this->pdo->query('SELECT * FROM billing_payments')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('in_old',$payment['provider_invoice_id']);
        self::assertSame('paid',$payment['status']);
        self::assertSame('2033-05-18 03:33:20',$payment['period_ends_at']);
        self::assertSame('2033-05-18 03:33:20',$this->pdo->query('SELECT valid_until FROM billing_entitlements')->fetchColumn());
        self::assertSame('2036-07-18 13:20:00',$this->pdo->query('SELECT current_period_ends_at FROM billing_subscriptions')->fetchColumn());
    }
    public function testPendingToPaidRefreshesPaymentPeriod():void
    {
        $this->http->latest='in_old';$this->http->paid=false;$this->http->periodEnd=1999999900;
        self::assertSame('processed',$this->deliver('evt_pending')['status']);
        $this->http->paid=true;$this->http->periodEnd=2000000000;
        self::assertSame('processed',$this->deliver('evt_paid')['status']);
        self::assertSame('2033-05-18 03:33:20',$this->pdo->query('SELECT period_ends_at FROM billing_payments')->fetchColumn());
    }
    public function testExpiredOldPaidInvoiceCannotGrantUnpaidCurrentPeriod():void
    {
        $this->http->periodEnd=time()-60;$this->http->periodStart=time()-86400;
        self::assertSame('processed',$this->deliver()['status']);
        self::assertSame('in_old',$this->pdo->query('SELECT provider_invoice_id FROM billing_payments')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_entitlements')->fetchColumn());
    }
    public function testRequestedInvoiceMustMatchAuthoritativeResponseId():void
    {
        $this->http->returnedInvoiceId='in_other';
        self::assertSame('rejected',$this->deliver()['status']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_payments')->fetchColumn());
    }
    public function testOlderSubscriptionObservationStillRecordsInvoiceWithoutUndoingCancellation():void
    {
        self::assertSame('processed',$this->deliver()['status']);
        $this->pdo->exec("DELETE FROM billing_payments; DELETE FROM billing_entitlements; UPDATE users SET plan_id=1; UPDATE billing_subscriptions SET status='canceled',last_confirmed_epoch=9223372036854775807");
        self::assertSame('processed',$this->deliver('evt_slow_invoice')['status']);
        self::assertSame('paid',$this->pdo->query('SELECT status FROM billing_payments')->fetchColumn());
        self::assertSame('canceled',$this->pdo->query('SELECT status FROM billing_subscriptions')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query('SELECT plan_id FROM users')->fetchColumn());
    }
    public function testMissingServicePeriodCannotUseSubscriptionCurrentPeriod():void
    {
        $this->http->missingLines=true;
        self::assertSame('rejected',$this->deliver()['status']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_payments')->fetchColumn());
    }
    public function testCaseSensitiveProviderIdsEmitDurablyWithoutRollingBackPayment():void
    {
        $this->pdo->exec('CREATE TABLE communication_notifications(id INTEGER PRIMARY KEY,user_id INTEGER,event TEXT,category TEXT,title TEXT,body TEXT,dedupe_key TEXT,UNIQUE(user_id,dedupe_key))');
        $this->pdo->exec('CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT UNIQUE,status TEXT,available_at TEXT)');
        $cipher=new SecretCipher(base64_encode(str_repeat('k',32)));
        $emitter=new \App\Communications\CommunicationEmitterService($this->pdo,$cipher,new \App\Communications\CommunicationEventCatalog());
        $this->processor=new BillingWebhookProcessor(new BillingRepository($this->pdo),new GatewaySettingsService($this->pdo,$cipher),$this->http,$emitter);
        $this->http->invoiceId='in_1AbCd';
        self::assertSame('processed',$this->deliver()['status']);
        self::assertSame('processed',$this->deliver('evt_duplicate_invoice')['status']);
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
        self::assertSame('in_1AbCd',$this->pdo->query('SELECT provider_invoice_id FROM billing_payments')->fetchColumn());
        $payload=json_decode($cipher->decrypt($this->pdo->query('SELECT payload_ciphertext FROM communication_email_outbox')->fetchColumn(),'clipforge:communications:v1'),true,32,JSON_THROW_ON_ERROR);
        self::assertSame('https://app.example.test/conta/pagamentos',$payload['variables']['link_assinatura']);
    }
}

final class InvoiceIdentityHttp implements HttpTransport
{
    public string $invoiceId='in_old';public string $latest='in_new';public bool $paid=true;public int $periodStart=1999990000;public int $periodEnd=2000000000;public ?string $returnedInvoiceId=null;public bool $missingLines=false;private string $attempt='';
    public function request(string $method,string $url,array $headers,array $body=[]):array
    {
        if($method==='POST'){$this->attempt=$body['client_reference_id'];return ['id'=>'cs_one','url'=>'https://checkout.stripe.com/c/pay/one','livemode'=>true];}
        if(str_contains($url,'/subscriptions/'))return ['id'=>'sub_one','livemode'=>true,'customer'=>'cus_one','metadata'=>['attempt_id'=>$this->attempt],'status'=>'active','latest_invoice'=>$this->latest,'items'=>['data'=>[['quantity'=>1,'current_period_start'=>2099990000,'current_period_end'=>2100000000,'price'=>['unit_amount'=>1990,'currency'=>'brl','recurring'=>['interval'=>'month','interval_count'=>1]]]]]];
        $requested=basename($url);$id=$requested===$this->invoiceId?($this->returnedInvoiceId??$requested):$requested;$paid=$requested===$this->invoiceId&&$this->paid;
        return ['id'=>$id,'livemode'=>true,'customer'=>'cus_one','parent'=>['subscription_details'=>['subscription'=>'sub_one']],'status'=>$paid?'paid':'open','amount_paid'=>$paid?1990:0,'total'=>1990,'currency'=>'brl','status_transitions'=>['paid_at'=>$paid?1999999000:null],'lines'=>['has_more'=>false,'data'=>$this->missingLines?[]:[['amount'=>1990,'currency'=>'brl','quantity'=>1,'parent'=>['type'=>'subscription_item_details','subscription_item_details'=>['subscription'=>'sub_one','proration'=>false]],'period'=>['start'=>$this->periodStart,'end'=>$this->periodEnd]]]]];
    }
}
