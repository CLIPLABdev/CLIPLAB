<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Billing\{BillingRepository,CheckoutService,GatewaySettingsService,HttpTransport};
use App\Core\{Request,Router,Session,View};
use App\Middleware\{AuthMiddleware,AdminMiddleware};
use App\Repositories\UserRepository;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingReturnUrlContractTest extends TestCase
{
    /** @dataProvider returnKinds */
    public function testProviderReturnUrlsReachReadOnlyOwnerStatus(string $field):void
    {
        Session::start();$_SESSION=[];Session::put('user_id',7);
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(file_get_contents(dirname(__DIR__).'/Fixtures/billing-schema.sql'));
        $pdo->exec("ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'active'; INSERT INTO users(id,plan_id,email) VALUES(7,1,'owner@example.test'),(8,1,'other@example.test'); INSERT INTO plans VALUES(1,'Free',0,1),(2,'Pro',1990,1)");
        $cipher=new SecretCipher(base64_encode(str_repeat('k',32)));$settings=new GatewaySettingsService($pdo,$cipher);
        $settings->save('stripe','sandbox',['secret'=>'sk_test_abcdefghijklmno','webhook_secret'=>'whsec_abcdefghijklmno','active'=>true],7);
        $http=new class implements HttpTransport {
            public array $body=[];public int $requests=0;
            public function request(string $method,string $url,array $headers,array $body=[]):array {
                ++$this->requests;if($method!=='POST')throw new \LogicException('A browser return must never confirm remotely.');
                $this->body=$body;return ['id'=>'cs_one','livemode'=>false,'url'=>'https://checkout.stripe.com/c/pay/one'];
            }
        };
        $attempt=(new CheckoutService(new BillingRepository($pdo),$settings,$http,'https://app.example.test'))->start(7,2,'stripe','sandbox',str_repeat('a',32));
        $router=new Router();$register=require dirname(__DIR__,2).'/routes/billing.php';
        $register($router,['pdo'=>$pdo,'cipher'=>$cipher,'view'=>new View(),'transport'=>$http,'authenticated'=>new AuthMiddleware(new UserRepository($pdo)),'admin_only'=>new AdminMiddleware(static fn(int $id)=>null),'user_profile'=>static fn(int $id)=>['id'=>$id,'name'=>'Owner','email'=>'owner@example.test','credits'=>0,'plan_name'=>'Free'],'return_base'=>'https://app.example.test']);
        $url=$http->body[$field];self::assertSame('https',parse_url($url,PHP_URL_SCHEME));
        $response=$router->dispatch(Request::fake('GET',(string)parse_url($url,PHP_URL_PATH)));
        self::assertSame(200,$response->status());
        self::assertStringContainsString('O retorno do navegador não ativa o plano',$response->body());
        self::assertStringContainsString('pending',$response->body());
        self::assertSame('pending',$pdo->query('SELECT status FROM billing_checkout_attempts')->fetchColumn());
        self::assertSame(1,(int)$pdo->query('SELECT plan_id FROM users WHERE id=7')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM billing_payments')->fetchColumn());
        self::assertSame(1,$http->requests);
        Session::put('user_id',8);self::assertSame(404,$router->dispatch(Request::fake('GET',(string)parse_url($url,PHP_URL_PATH)))->status());
        $_SESSION=[];
    }
    public static function returnKinds():array{return [['success_url'],['cancel_url']];}
}
