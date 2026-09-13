<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Billing\{CurlHttpTransport,HostedCheckoutUrl,PagarmeGateway,HttpTransport,BillingRepository};
use PHPUnit\Framework\TestCase;
use PDO;

final class BillingProviderSafetyTest extends TestCase
{
    public function testTransportRejectsUntrustedDestinationsBeforeNetworking(): void
    {
        $this->expectException(\DomainException::class);
        (new CurlHttpTransport())->request('POST','https://api.stripe.com.evil.test/v1/checkout/sessions',['Authorization'=>'secret']);
    }
    public function testHostedCheckoutRejectsCredentialsAndHostConfusion(): void
    {
        $this->expectException(\DomainException::class);
        HostedCheckoutUrl::validate('https://checkout.stripe.com@evil.test/path','stripe');
    }
    public function testPagarmeHostedSubscriptionUsesDedicatedPlanAndAuthoritativeInvoice(): void
    {
        $pdo=new PDO('sqlite::memory:');$pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $pdo->exec("INSERT INTO billing_checkout_attempts(id,provider_plan_id) VALUES('attempt1',NULL)");
        $http=new class implements HttpTransport {
            public array $calls=[];
            public function request(string $method,string $url,array $headers,array $body=[]): array {
                $this->calls[]=compact('method','url','body');
                if($method==='POST' && str_ends_with($url,'/plans'))return ['id'=>'plan_unique'];
                if($method==='POST')return ['id'=>'pl_one','url'=>'https://payment-link.pagar.me/pl_one'];
                if(str_contains($url,'/invoices/'))return ['id'=>'in_one','status'=>'paid','amount'=>1990,'subscription'=>['id'=>'sub_one'],'customer'=>['id'=>'cus_one'],'cycle'=>['start_at'=>gmdate('c',time()-30),'end_at'=>gmdate('c',time()+86400)],'charge'=>['status'=>'paid','currency'=>'BRL','amount'=>1990,'paid_at'=>gmdate('c')]];
                if(str_contains($url,'/plans/'))return ['id'=>'plan_unique','metadata'=>['attempt_id'=>'attempt1']];
                return ['id'=>'sub_one','plan'=>['id'=>'plan_unique'],'customer'=>['id'=>'cus_one'],'status'=>'active','currency'=>'BRL','interval'=>'month','interval_count'=>1,'items'=>[['quantity'=>1,'pricing_scheme'=>['price'=>1990]]],'current_cycle'=>['start_at'=>gmdate('c',time()-30),'end_at'=>gmdate('c',time()+86400)]];
            }
        };
        $adapter=new PagarmeGateway($http,'sk_test_placeholder','sandbox',new BillingRepository($pdo));
        $attempt=['id'=>'attempt1','provider_plan_id'=>null];$quote=['plan_name'=>'Pro','amount_cents'=>1990,'currency'=>'BRL'];
        $result=$adapter->create($attempt,$quote);
        self::assertSame('pl_one',$result['provider_checkout_id']);
        self::assertSame('plan_unique',$pdo->query('SELECT provider_plan_id FROM billing_checkout_attempts')->fetchColumn());
        self::assertSame(['credit_card'],$http->calls[1]['body']['payment_settings']['accepted_payment_methods']);
        self::assertSame(1,$http->calls[1]['body']['max_sessions']);
        $attempt['provider_plan_id']='plan_unique';
        self::assertSame('paid',$adapter->confirm($attempt,$quote,'in_one','invoice.paid')['payment_status']);
    }
    public function testPagarmeCallbackCannotForgePaidStatusAndIsReplaySafe(): void
    {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec(file_get_contents(__DIR__.'/../Fixtures/billing-schema.sql'));
        $pdo->exec("INSERT INTO users VALUES(7,1,'owner@example.test'); INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1)");
        $settings=new \App\Billing\GatewaySettingsService($pdo,new \App\Security\SecretCipher(base64_encode(str_repeat('k',32))));
        $settings->save('pagarme','production',['secret'=>'sk_live_abcdefghijklmno','webhook_secret'=>str_repeat('a',64),'active'=>true],7);
        $http=new class implements HttpTransport {
            public bool $paid=false;public string $attempt='';
            public function request(string $method,string $url,array $headers,array $body=[]): array {
                if($method==='POST' && str_ends_with($url,'/plans')){$this->attempt=$body['metadata']['attempt_id'];return ['id'=>'plan_unique'];}
                if($method==='POST')return ['id'=>'pl_one','url'=>'https://payment-link.pagar.me/pl_one'];
                if(str_contains($url,'/invoices/'))return ['id'=>'in_one','status'=>$this->paid?'paid':'failed','amount'=>1990,'subscription'=>['id'=>'sub_one'],'customer'=>['id'=>'cus_one'],'cycle'=>['start_at'=>gmdate('c',time()-30),'end_at'=>gmdate('c',time()+86400)],'charge'=>['status'=>$this->paid?'paid':'failed','currency'=>'BRL','amount'=>1990,'paid_at'=>gmdate('c')]];
                if(str_contains($url,'/plans/'))return ['id'=>'plan_unique','metadata'=>['attempt_id'=>$this->attempt]];
                return ['id'=>'sub_one','plan'=>['id'=>'plan_unique'],'customer'=>['id'=>'cus_one'],'status'=>'active','currency'=>'BRL','interval'=>'month','interval_count'=>1,'items'=>[['quantity'=>1,'pricing_scheme'=>['price'=>1990]]]];
            }
        };
        $repo=new BillingRepository($pdo);(new \App\Billing\CheckoutService($repo,$settings,$http,'https://app.example.test'))->start(7,2,'pagarme','production',str_repeat('b',32));
        $processor=new \App\Billing\BillingWebhookProcessor($repo,$settings,$http);
        $raw=json_encode(['id'=>'hook_one','type'=>'invoice.paid','data'=>['id'=>'in_one','status'=>'paid','amount'=>1990]]);
        self::assertSame('processed',$processor->process('pagarme','production',$raw,str_repeat('a',64))['status']);
        self::assertSame('failed',$repo->history(7)[0]['status']);self::assertSame(1,(int)$pdo->query('SELECT plan_id FROM users')->fetchColumn());
        $http->paid=true;$raw=str_replace('hook_one','hook_two',$raw);
        self::assertSame('processed',$processor->process('pagarme','production',$raw,str_repeat('a',64))['status']);
        self::assertSame('duplicate',$processor->process('pagarme','production',$raw,str_repeat('a',64))['status']);
        self::assertCount(1,$repo->history(7));self::assertSame(2,(int)$pdo->query('SELECT plan_id FROM users')->fetchColumn());
        $this->expectException(\DomainException::class);$processor->process('pagarme','production',$raw,'bad-token');
    }
}
