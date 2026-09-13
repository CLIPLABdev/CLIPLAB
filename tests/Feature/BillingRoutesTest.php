<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class BillingRoutesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__) . '/Fixtures/billing-schema.sql'));
        $this->pdo->exec('ALTER TABLE users ADD COLUMN name TEXT');
        $this->pdo->exec('ALTER TABLE users ADD COLUMN credits INTEGER DEFAULT 0');
        $this->pdo->exec('ALTER TABLE users ADD COLUMN status TEXT DEFAULT "active"');
        $this->pdo->exec('CREATE TABLE rate_limits (id INTEGER PRIMARY KEY, rate_key TEXT, action TEXT, window_started_at TEXT, attempts INTEGER, expires_at TEXT)');
        $this->pdo->exec("INSERT INTO plans VALUES (1, 'Grátis', 0, 1), (2, 'Pro', 1990, 1)");
        $this->pdo->exec("INSERT INTO users (id,plan_id,email,name,credits,status) VALUES (7,1,'ana@example.test','Ana',3,'active'),(8,1,'bia@example.test','Bia',9,'active')");
        $this->pdo->exec("INSERT INTO billing_payments (id,user_id,subscription_id,plan_id,provider,environment,provider_payment_id,provider_invoice_id,status,currency,gross_amount_cents,paid_amount_cents,refunded_amount_cents,paid_at) VALUES (1,7,10,2,'stripe','production','in_1','in_1','refunded','BRL',1990,1990,100,'2026-09-07 12:00:00'),(2,8,11,2,'pagarme','production','in_2','in_2','paid','BRL',2990,2990,0,'2026-09-06 12:00:00')");
        $this->pdo->exec("INSERT INTO billing_checkout_attempts (id,user_id,plan_id,provider,environment,request_key,quote_json,status,created_epoch) VALUES ('owner-attempt',7,2,'stripe','production','request_key_abcdefghijklmnopqrstuvwxyz','{}','pending',1),('other-attempt',8,2,'stripe','production','request_key_abcdefghijklmnopqrstuvwxyz2','{}','pending',1)");
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGuestsAreRedirectedBeforeBillingOrFinanceDataCanBeRead(): void
    {
        $router = $this->router();
        foreach (['/checkout/plano/2', '/checkout/retorno/owner-attempt', '/conta/pagamentos', '/admin/financeiro', '/admin/gateways'] as $path) {
            $response = $router->dispatch(Request::fake('GET', $path));
            self::assertSame(302, $response->status(), $path);
            self::assertSame('/login', $response->header('Location'), $path);
        }
    }

    public function testReviewDisclosesUnconfiguredGatewayAndPostStillNeedsCsrf(): void
    {
        Session::put('user_id', 7);
        $router = $this->router();

        $review = $router->dispatch(Request::fake('GET', '/checkout/plano/2'));
        self::assertSame(200, $review->status());
        self::assertSame('private, no-store', $review->header('Cache-Control'));
        self::assertStringContainsString('Gateway indisponível', $review->body());
        self::assertStringContainsString('disabled', $review->body());
        self::assertStringContainsString('Ambiente de testes', $review->body());
        self::assertStringNotContainsString('Pagamento aprovado', $review->body());

        $post = $router->dispatch(Request::fake('POST', '/checkout/2', ['request_key' => str_repeat('a', 32)]));
        self::assertSame(419, $post->status());
    }

    public function testReturnAndStatusAreOwnerScopedAndNeverExposeEntitlement(): void
    {
        Session::put('user_id', 7);
        $router = $this->router();

        $return = $router->dispatch(Request::fake('GET', '/checkout/retorno/owner-attempt'));
        self::assertSame(200, $return->status());
        self::assertStringContainsString('Aguardando confirmação', $return->body());
        self::assertStringContainsString('não ativa o plano', $return->body());

        $status = $router->dispatch(Request::fake('GET', '/checkout/owner-attempt/status'));
        self::assertSame(200, $status->status());
        self::assertSame('pending', json_decode($status->body(), true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertStringNotContainsString('plan_id', $status->body());

        self::assertSame(404, $router->dispatch(Request::fake('GET', '/checkout/retorno/other-attempt'))->status());
        self::assertSame(404, $router->dispatch(Request::fake('GET', '/checkout/other-attempt/status'))->status());
    }

    public function testFinanceFiltersOnlyBillingPaymentsAndGatewaySaveNeverRendersSecrets(): void
    {
        Session::put('user_id', 7);
        $router = $this->router();

        $denied = $router->dispatch(Request::fake('GET', '/admin/financeiro'));
        self::assertSame(403, $denied->status());

        Session::put('user_id', 1);
        $finance = $router->dispatch(Request::fake('GET', '/admin/financeiro?gateway=stripe&status=refunded&user=7&plan=2&from=2026-09-07&to=2026-09-07'));
        self::assertSame(200, $finance->status());
        self::assertStringContainsString('ana@example.test', $finance->body());
        self::assertStringNotContainsString('bia@example.test', $finance->body());
        self::assertStringContainsString('R$ 18,90', $finance->body());
        self::assertStringNotContainsString('credit_transactions', $finance->body());

        $gateways = $router->dispatch(Request::fake('GET', '/admin/gateways'));
        self::assertSame(200, $gateways->status());
        self::assertStringNotContainsString('sk_test_', $gateways->body());

        $save = $router->dispatch(Request::fake('POST', '/admin/gateways/stripe/sandbox', [
            '_token' => Csrf::token(),
            'secret' => 'sk_test_abcdefghijk',
            'webhook_secret' => 'whsec_abcdefghijk',
            'active' => '1',
        ]));
        self::assertSame(302, $save->status());
        $saved = $this->pdo->query("SELECT secret_ciphertext, webhook_secret_ciphertext FROM billing_gateway_settings WHERE provider='stripe' AND environment='sandbox'")->fetch(PDO::FETCH_ASSOC);
        self::assertNotSame('sk_test_abcdefghijk', $saved['secret_ciphertext']);
        self::assertNotSame('whsec_abcdefghijk', $saved['webhook_secret_ciphertext']);
    }

    public function testWebhookRoutesUseRawAuthenticationAndRetryWith503WithoutCsrf(): void
    {
        $received = [];
        $router = $this->router([
            'webhook_processor' => static function (string $provider, string $environment, string $body, string $authentication) use (&$received): array {
                $received = compact('provider', 'environment', 'body', 'authentication');
                return ['status' => $provider === 'stripe' ? 'retry' : 'processed'];
            },
            'webhook_rate_limit' => static fn (): bool => true,
        ]);

        $stripe = $router->dispatch(Request::fakeRaw('POST', '/webhooks/stripe/sandbox', '{"id":"evt_1"}', ['Stripe-Signature' => 'sig_exact']));
        self::assertSame(503, $stripe->status());
        self::assertSame(['provider' => 'stripe', 'environment' => 'sandbox', 'body' => '{"id":"evt_1"}', 'authentication' => 'sig_exact'], $received);

        $pagarme = $router->dispatch(Request::fakeRaw('POST', '/webhooks/pagarme/sandbox/endpoint-token', '{"id":"evt_2"}'));
        self::assertSame(200, $pagarme->status());
        self::assertSame('pagarme', $received['provider']);
        self::assertSame('endpoint-token', $received['authentication']);
    }

    public function testAdminCanCreateEditAndDeactivateCouponsWithCsrfAndValidation(): void
    {
        Session::put('user_id', 7);
        self::assertSame(403, $this->router()->dispatch(Request::fake('GET', '/admin/cupons'))->status());
        Session::put('user_id', 1); $router=$this->router();
        self::assertSame(200, $router->dispatch(Request::fake('GET', '/admin/cupons'))->status());
        self::assertSame(419, $router->dispatch(Request::fake('POST', '/admin/cupons', ['code'=>'OFF10']))->status());
        $create=$router->dispatch(Request::fake('POST','/admin/cupons',['_token'=>Csrf::token(),'code'=>'OFF10','discount_type'=>'percent','discount_value'=>'10','currency'=>'BRL','per_user_limit'=>'1','max_redemptions'=>'','starts_at'=>'','ends_at'=>'','is_active'=>'1','plan_ids'=>['2']]));
        self::assertSame(302,$create->status()); self::assertSame('OFF10',$this->pdo->query('SELECT code FROM coupons')->fetchColumn());
        $edit=$router->dispatch(Request::fake('POST','/admin/cupons/1',['_token'=>Csrf::token(),'code'=>'OFF10','discount_type'=>'percent','discount_value'=>'10','currency'=>'BRL','per_user_limit'=>'1','max_redemptions'=>'','starts_at'=>'','ends_at'=>'','plan_ids'=>['2']]));
        self::assertSame(302,$edit->status()); self::assertSame(0,(int)$this->pdo->query('SELECT is_active FROM coupons WHERE id=1')->fetchColumn());
    }

    public function testMalformedCheckoutFieldsNeverCreateAnAttempt(): void
    {
        Session::put('user_id',7);$router=$this->router();
        $review=$router->dispatch(Request::fake('GET','/checkout/plano/2?coupon[]=OFF10&provider[]=stripe&environment[]=production'));
        self::assertSame(200,$review->status());self::assertStringContainsString('Dados de checkout inválidos',$review->body());
        $post=$router->dispatch(Request::fake('POST','/checkout/2',['_token'=>Csrf::token(),'request_key'=>['not-a-key'],'provider'=>['stripe'],'environment'=>['production'],'coupon'=>['OFF10']]));
        self::assertSame(302,$post->status());self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM billing_checkout_attempts')->fetchColumn());
    }

    public function testReviewKeepsLiveCheckoutKeyButRenewsOnlyAProvenTerminalAttempt(): void
    {
        Session::put('user_id',7);$key='request_key_abcdefghijklmnopqrstuvwxyz';Session::put('billing_request_key_2',$key);$this->pdo->exec("UPDATE billing_checkout_attempts SET status='expired' WHERE id='owner-attempt'");
        $router=$this->router();$expired=$router->dispatch(Request::fake('GET','/checkout/plano/2'));
        self::assertSame(200,$expired->status());self::assertNotSame($key,Session::get('billing_request_key_2'));self::assertStringNotContainsString('value="'.$key.'"',$expired->body());
        Session::put('billing_request_key_2',$key);$this->pdo->exec("UPDATE billing_checkout_attempts SET status='ambiguous' WHERE id='owner-attempt'");
        $live=$router->dispatch(Request::fake('GET','/checkout/plano/2'));
        self::assertSame(200,$live->status());self::assertSame($key,Session::get('billing_request_key_2'));self::assertStringContainsString('value="'.$key.'"',$live->body());
    }

    public function testCouponEditWithoutPlanInputPreservesSavedEligibility(): void
    {
        $this->pdo->exec("INSERT INTO coupons(id,code,discount_type,discount_value,currency,per_user_limit,is_active) VALUES(1,'OFF10','percent',10,'BRL',1,1); INSERT INTO coupon_plans(coupon_id,plan_id) VALUES(1,2)");Session::put('user_id',1);$router=$this->router();
        $response=$router->dispatch(Request::fake('POST','/admin/cupons/1',['_token'=>Csrf::token(),'code'=>'OFF10','discount_type'=>'percent','discount_value'=>'10','currency'=>'BRL','per_user_limit'=>'1','max_redemptions'=>'','starts_at'=>'','ends_at'=>'','is_active'=>'1']));
        self::assertSame(302,$response->status());self::assertSame([2],array_map('intval',$this->pdo->query('SELECT plan_id FROM coupon_plans WHERE coupon_id=1')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertStringContainsString('data-coupon-plan="2"',$router->dispatch(Request::fake('GET','/admin/cupons'))->body());
    }

    public function testHistoryMakesPagarmeImmediateCancellationExplicit(): void
    {
        $this->pdo->exec("INSERT INTO billing_subscriptions(id,user_id,plan_id,provider,environment,provider_subscription_id,status,currency,amount_cents,plan_snapshot,provider_customer_id,checkout_attempt_id) VALUES(12,7,2,'pagarme','production','sub_pagarme','active','BRL',1990,'{}','cus_pagarme','owner-attempt')");Session::put('user_id',7);
        $history=$this->router()->dispatch(Request::fake('GET','/conta/pagamentos'));
        self::assertSame(200,$history->status());self::assertStringContainsString('cancelamento imediato',$history->body());self::assertStringContainsString('confirm_immediate',$history->body());self::assertStringContainsString('Reconciliar status',$history->body());
    }

    /** @param array<string,mixed> $overrides */
    private function router(array $overrides = []): Router
    {
        $router = new Router();
        $register = require dirname(__DIR__, 2) . '/routes/billing.php';
        $deps = [
            'pdo' => $this->pdo,
            'view' => new View(),
            'cipher' => new SecretCipher(base64_encode(str_repeat('x', 32))),
            'authenticated' => static function (Request $request, callable $next): \App\Core\Response {
                return (int) Session::get('user_id', 0) > 0 ? $next($request) : \App\Core\Response::redirect('/login');
            },
            'admin_only' => static function (Request $request, callable $next): \App\Core\Response {
                $id = (int) Session::get('user_id', 0);
                return $id === 1 ? $next($request) : ($id === 0 ? \App\Core\Response::redirect('/login') : \App\Core\Response::text('Acesso negado.', 403));
            },
            'user_profile' => fn (int $id): ?array => $this->pdo->query('SELECT id,name,email,credits FROM users WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC) ?: null,
            'admin_identity' => static fn (int $id): ?array => $id === 1 ? ['name' => 'Root', 'email' => 'root@example.test'] : null,
            'provider' => 'stripe',
            'environment' => 'sandbox',
            'return_base' => 'http://localhost',
        ];
        $register($router, $overrides + $deps);

        return $router;
    }
}
