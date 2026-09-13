<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Billing\{GatewaySettingsService,HttpTransport};
use App\Core\{Csrf,Request,Router,Session,View};
use App\Security\SecretCipher;
use DOMDocument;
use DOMXPath;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingFormSafetyTest extends TestCase
{
    private PDO $pdo;private Router $router;private FormGatewayHttp $http;
    protected function setUp():void
    {
        Session::start();$_SESSION=[];Session::put('user_id',7);
        $this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(file_get_contents(dirname(__DIR__).'/Fixtures/billing-schema.sql'));
        $this->pdo->exec("ALTER TABLE users ADD COLUMN name TEXT;ALTER TABLE users ADD COLUMN credits INTEGER DEFAULT 0;INSERT INTO users(id,plan_id,email,name) VALUES(7,1,'owner@example.test','Owner');INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1)");
        $cipher=new SecretCipher(base64_encode(str_repeat('k',32)));$settings=new GatewaySettingsService($this->pdo,$cipher);
        foreach(['sandbox'=>'test','production'=>'live'] as $environment=>$mode)$settings->save('stripe',$environment,['secret'=>'sk_'.$mode.'_abcdefghijklmno','webhook_secret'=>'whsec_abcdefghijklmno','active'=>true],7);
        $this->http=new FormGatewayHttp();$this->router=new Router();$register=require dirname(__DIR__,2).'/routes/billing.php';
        $register($this->router,['pdo'=>$this->pdo,'cipher'=>$cipher,'view'=>new View(),'transport'=>$this->http,'authenticated'=>static fn($r,$next)=>$next($r),'admin_only'=>static fn($r,$next)=>$next($r),'user_profile'=>static fn(int $id)=>['id'=>$id,'name'=>'Owner','email'=>'owner@example.test','credits'=>0,'plan_name'=>'Free'],'admin_identity'=>static fn(int $id)=>['name'=>'Admin','email'=>'admin@example.test'],'return_base'=>'https://app.example.test']);
    }
    /** @dataProvider environments */
    public function testSelectedOptionCarriesItsEnvironmentThroughRealFormToGateway(string $initial,string $chosen,string $secret):void
    {
        $review=$this->router->dispatch(Request::fake('GET','/checkout/plano/2?provider=stripe&environment='.$initial));
        self::assertStringContainsString('Produção pode gerar cobranças reais',$review->body());
        $fields=$this->checkoutFields($review->body(),'stripe · '.$chosen);
        $response=$this->router->dispatch(Request::fake('POST','/checkout/2',$fields));
        self::assertSame('https://checkout.stripe.com/c/pay/one',$response->header('Location'));
        self::assertSame($chosen,$this->pdo->query('SELECT environment FROM billing_checkout_attempts')->fetchColumn());
        self::assertSame('Bearer '.$secret,$this->http->calls[0]['headers']['Authorization']);
        $this->router->dispatch(Request::fake('POST','/checkout/2',$fields));
        self::assertCount(1,$this->http->calls,'A retry of the same selected pair must stay idempotent.');
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_checkout_attempts')->fetchColumn());
    }
    public static function environments():array{return [['production','sandbox','sk_test_abcdefghijklmno'],['sandbox','production','sk_live_abcdefghijklmno']];}
    /** @dataProvider invalidSelections */
    public function testMalformedOrAmbiguousSelectionNeverFallsBackToProduction(array $selection):void
    {
        $review=$this->router->dispatch(Request::fake('GET','/checkout/plano/2?provider=stripe&environment=production'));
        $fields=['_token'=>Csrf::token(),'request_key'=>Session::get('billing_request_key_2'),'coupon'=>'']+$selection;
        $response=$this->router->dispatch(Request::fake('POST','/checkout/2',$fields));
        self::assertSame('/checkout/plano/2',$response->header('Location'));
        self::assertCount(0,$this->http->calls);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_checkout_attempts')->fetchColumn());
    }
    public static function invalidSelections():array{return [
        [['gateway'=>['stripe:sandbox']]], [['gateway'=>'stripe']], [['gateway'=>'stripe:unknown']], [['gateway'=>'pagarme:sandbox']],
        [['gateway'=>'stripe:sandbox','provider'=>'stripe','environment'=>'production']], [['gateway'=>'stripe:sandbox','environment'=>['production']]],
        [['provider'=>'stripe']], [[]],
    ];}
    public function testExplicitLegacyProviderAndEnvironmentRemainSupported():void
    {
        $this->router->dispatch(Request::fake('GET','/checkout/plano/2'));
        $this->router->dispatch(Request::fake('POST','/checkout/2',['_token'=>Csrf::token(),'request_key'=>Session::get('billing_request_key_2'),'provider'=>'stripe','environment'=>'sandbox','coupon'=>'']));
        self::assertSame('sandbox',$this->pdo->query('SELECT environment FROM billing_checkout_attempts')->fetchColumn());
    }
    public function testExplicitEmptyCheckboxScopeMeansAllPlansButMissingScopePreserves():void
    {
        $this->pdo->exec("INSERT INTO coupons(id,code,discount_type,discount_value,currency,per_user_limit,is_active) VALUES(1,'SAVE10','percent',10,'BRL',1,1);INSERT INTO coupon_plans VALUES(1,2)");
        $fields=['_token'=>Csrf::token(),'code'=>'SAVE10','discount_type'=>'percent','discount_value'=>'20','per_user_limit'=>'1','is_active'=>'1'];
        $this->router->dispatch(Request::fake('POST','/admin/cupons/1',$fields));
        self::assertSame([2],array_map('intval',$this->pdo->query('SELECT plan_id FROM coupon_plans')->fetchAll(PDO::FETCH_COLUMN)));
        $this->router->dispatch(Request::fake('POST','/admin/cupons/1',$fields+['plan_scope_present'=>'1']));
        self::assertSame([],$this->pdo->query('SELECT plan_id FROM coupon_plans')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('success',Session::pull('admin_flash')['type']);
    }
    public function testNewCouponCanExplicitlyApplyToAllPlans():void
    {
        $html=$this->router->dispatch(Request::fake('GET','/admin/cupons'))->body();
        $doc=new DOMDocument();@$doc->loadHTML($html);$xpath=new DOMXPath($doc);
        self::assertSame(1,$xpath->query('//form[@action="/admin/cupons"]/input[@name="plan_scope_present" and @value="1"]')->length);
        $this->router->dispatch(Request::fake('POST','/admin/cupons',['_token'=>Csrf::token(),'code'=>'ALL10','discount_type'=>'percent','discount_value'=>'10','per_user_limit'=>'1','is_active'=>'1','plan_scope_present'=>'1']));
        self::assertSame('ALL10',$this->pdo->query('SELECT code FROM coupons')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM coupon_plans')->fetchColumn());
    }
    /** @dataProvider malformedScopes */
    public function testMalformedScopeCannotWidenOrModifyCoupon(array $extra):void
    {
        $this->pdo->exec("INSERT INTO coupons(id,code,discount_type,discount_value,currency,per_user_limit,is_active) VALUES(1,'SAVE10','percent',10,'BRL',1,1);INSERT INTO coupon_plans VALUES(1,2)");
        $this->router->dispatch(Request::fake('POST','/admin/cupons/1',['_token'=>Csrf::token(),'code'=>'SAVE10','discount_type'=>'percent','discount_value'=>'20','per_user_limit'=>'1','is_active'=>'1']+$extra));
        self::assertSame(10,(int)$this->pdo->query('SELECT discount_value FROM coupons')->fetchColumn());
        self::assertSame([2],array_map('intval',$this->pdo->query('SELECT plan_id FROM coupon_plans')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame('error',Session::pull('admin_flash')['type']);
    }
    public static function malformedScopes():array{return [[['plan_scope_present'=>['1'],'plan_ids'=>[]]],[['plan_scope_present'=>'1','plan_ids'=>'2']],[['plan_scope_present'=>'1','plan_ids'=>[['2']]]],[['plan_scope_present'=>'1','plan_ids'=>null]]];}
    private function checkoutFields(string $html,string $chosenLabel):array
    {
        $doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($doc);$fields=[];
        foreach($xpath->query('//form[@action="/checkout/2"]//input[@name]') as $node)$fields[$node->getAttribute('name')]=$node->getAttribute('value');
        $chosen=false;
        foreach($xpath->query('//form[@action="/checkout/2"]//select/option') as $node)if(trim($node->textContent)===$chosenLabel){$fields[$node->parentNode->getAttribute('name')]=$node->getAttribute('value');$chosen=true;}
        self::assertTrue($chosen,'The chosen visible gateway option must exist.');return $fields;
    }
}
final class FormGatewayHttp implements HttpTransport
{
    public array $calls=[];
    public function request(string $method,string $url,array $headers,array $body=[]):array
    {
        $this->calls[]=compact('method','url','headers','body');
        if($method!=='POST')throw new \LogicException('Unexpected financial lookup.');
        return ['id'=>'cs_one','livemode'=>str_contains($headers['Authorization'],'sk_live_'),'url'=>'https://checkout.stripe.com/c/pay/one'];
    }
}
