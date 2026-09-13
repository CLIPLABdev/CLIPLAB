<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use App\Core\Router;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

final class AdminRoutesTest extends TestCase
{
    private PDO $pdo;
    /** @var array{plan_id:int,admin_id:int,user_id:int,other_admin_id:int} */
    private array $ids;

    protected function setUp(): void
    {
        $this->pdo = AdminTestDatabase::create();
        $this->ids = AdminTestDatabase::seed($this->pdo);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGuestUserAndAdminAreSeparatedOnEveryAdminRoute(): void
    {
        $router = $this->router();
        $guest = $router->dispatch(Request::fake('GET', '/admin'));
        self::assertSame(302, $guest->status());
        self::assertSame('/login', $guest->header('Location'));

        $_SESSION = ['user_id' => $this->ids['user_id']];
        self::assertSame(403, $router->dispatch(Request::fake('GET', '/admin'))->status());

        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $admin = $router->dispatch(Request::fake('GET', '/admin'));
        self::assertSame(200, $admin->status());
        self::assertStringContainsString('Administração', $admin->body());
        self::assertSame('no-store, private', $admin->header('Cache-Control'));
    }

    public function testPostMutationRequiresCsrfBeforeControllerAndInvalidFilterDoesNotAlterSql(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $router = $this->router();

        $csrf = $router->dispatch(Request::fake('POST', '/admin/usuarios/' . $this->ids['user_id'] . '/status', [
            'status' => 'suspended', 'reason' => 'Teste',
        ]));
        self::assertSame(419, $csrf->status());
        self::assertSame('active', $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->ids['user_id'])->fetchColumn());

        $safe = $router->dispatch(Request::fake('GET', "/admin/usuarios?status=active%27%20OR%201%3D1--"));
        self::assertSame(200, $safe->status());
        self::assertStringContainsString('3 usuários', $safe->body());
    }

    public function testEveryAdministrativeGetRouteRendersWithoutPrivatePayloads(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $router = $this->router();

        foreach (['/admin', '/admin/usuarios', '/admin/projetos', '/admin/videos', '/admin/jobs', '/admin/erros', '/admin/creditos', '/admin/planos', '/admin/logs', '/admin/configuracoes/gemini'] as $path) {
            $response = $router->dispatch(Request::fake('GET', $path));
            self::assertSame(200, $response->status(), $path);
            self::assertStringNotContainsString('payload_json', $response->body(), $path);
            self::assertStringNotContainsString('lease_token_hash', $response->body(), $path);
            self::assertStringNotContainsString('<video', strtolower($response->body()), $path);
        }
    }

    public function testValidCsrfMutationUsesActorAndCreatesAudit(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $router = $this->router();

        $response = $router->dispatch(Request::fake('POST', '/admin/creditos/ajustar', [
            '_token' => str_repeat('c', 64),
            'user_id' => (string) $this->ids['user_id'],
            'amount' => '2',
            'reason' => 'Ajuste de integração HTTP',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/admin/creditos', $response->header('Location'));
        self::assertSame(12, (int) $this->pdo->query('SELECT credits FROM users WHERE id = ' . $this->ids['user_id'])->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM system_logs WHERE event_code = 'admin.credits_adjusted' AND actor_id = {$this->ids['admin_id']}")->fetchColumn());
    }

    public function testAdminCanViewCreateAndEditAnAccountThroughProtectedRoutes(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $router = $this->router();

        $detail = $router->dispatch(Request::fake('GET', '/admin/usuarios/' . $this->ids['user_id']));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('Histórico de créditos', $detail->body());

        $created = $router->dispatch(Request::fake('POST', '/admin/usuarios', [
            '_token' => str_repeat('c', 64), 'name' => 'Nova Pessoa', 'email' => 'nova@example.test',
            'password' => 'Strong-password-3', 'plan_id' => (string) $this->ids['plan_id'], 'role' => 'user', 'reason' => 'Cadastro assistido',
        ]));
        self::assertSame(302, $created->status());
        $newId = (int) $this->pdo->query("SELECT id FROM users WHERE email = 'nova@example.test'")->fetchColumn();
        self::assertGreaterThan(0, $newId);

        $edited = $router->dispatch(Request::fake('POST', '/admin/usuarios/' . $newId, [
            '_token' => str_repeat('c', 64), 'name' => 'Nova Editada', 'email' => 'editada@example.test', 'role' => 'user', 'reason' => 'Correção de cadastro',
        ]));
        self::assertSame(302, $edited->status());
        self::assertSame('Nova Editada', $this->pdo->query('SELECT name FROM users WHERE id = ' . $newId)->fetchColumn());
    }

    public function testAdminCanCreateAPlan(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $response = $this->router()->dispatch(Request::fake('POST', '/admin/planos', ['_token'=>str_repeat('c',64),'slug'=>'creator','name'=>'Creator','price_cents'=>'1990','monthly_minutes'=>'60','credits'=>'20','max_upload_bytes'=>'104857600','storage_bytes'=>'1073741824','is_active'=>'1']));
        self::assertSame(302, $response->status());
        self::assertSame('Creator', $this->pdo->query("SELECT name FROM plans WHERE slug='creator'")->fetchColumn());
    }

    public function testAdminCanSavePlatformSettingsAndManagePromotions(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)]; $router=$this->router();
        self::assertSame(200, $router->dispatch(Request::fake('GET','/admin/configuracoes'))->status());
        $saved=$router->dispatch(Request::fake('POST','/admin/configuracoes',['_token'=>str_repeat('c',64),'name'=>'ClipForge Pro','description'=>'Vídeos melhores','logo_url'=>'/assets/images/logo.png','favicon_url'=>'/assets/images/favicon.ico']));
        self::assertSame(302,$saved->status()); self::assertSame('ClipForge Pro',$this->pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='name'")->fetchColumn());
        self::assertSame(200,$router->dispatch(Request::fake('GET','/admin/promocoes'))->status());
        $created=$router->dispatch(Request::fake('POST','/admin/promocoes',['_token'=>str_repeat('c',64),'title'=>'Upgrade','body'=>'Mais minutos','cta_label'=>'Ver plano','cta_url'=>'/conta/plano','placement'=>'dashboard','audience'=>'plan','plan_id'=>(string)$this->ids['plan_id'],'is_active'=>'1']));
        self::assertSame(302,$created->status()); self::assertSame('Upgrade',$this->pdo->query('SELECT title FROM promotions')->fetchColumn());
        $id=(int)$this->pdo->query('SELECT id FROM promotions')->fetchColumn();
        $edited=$router->dispatch(Request::fake('POST','/admin/promocoes/'.$id,['_token'=>str_repeat('c',64),'title'=>'Oferta editada','body'=>'Mais','placement'=>'dashboard','audience'=>'all','delivery_kind'=>'notice','is_active'=>'0']));
        self::assertSame(302,$edited->status()); self::assertSame('notice',$this->pdo->query('SELECT delivery_kind FROM promotions WHERE id='.$id)->fetchColumn());
        $router->dispatch(Request::fake('POST','/admin/promocoes/'.$id.'/excluir',['_token'=>str_repeat('c',64)]));
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM promotions')->fetchColumn());
        $deleted=$router->dispatch(Request::fake('POST','/admin/promocoes/'.$id.'/excluir',['_token'=>str_repeat('c',64),'confirm'=>'EXCLUIR']));
        self::assertSame(302,$deleted->status()); self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM promotions')->fetchColumn());
    }

    public function testMissingMasterKeyShowsSafeActionableMessageAndDoesNotStoreSecret(): void
    {
        $_SESSION = ['user_id' => $this->ids['admin_id'], '_csrf' => str_repeat('c', 64)];
        $router = $this->router('');
        $response = $router->dispatch(Request::fake('POST', '/admin/configuracoes/gemini', [
            '_token' => str_repeat('c', 64), 'model' => 'gemini-2.5-flash', 'api_key' => 'valid-test-key-123',
        ]));
        self::assertSame(302, $response->status());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM gemini_settings')->fetchColumn());

        $page = $router->dispatch(Request::fake('GET', '/admin/configuracoes/gemini'));
        self::assertSame(200, $page->status());
        self::assertStringContainsString('APP_ENCRYPTION_KEY', $page->body());
        self::assertStringNotContainsString('valid-test-key-123', $page->body());
    }

    public function testArchivedAccountRequiresExplicitRestoreInsteadOfStatusActivation():void
    {
        $_SESSION=['user_id'=>$this->ids['admin_id'],'_csrf'=>str_repeat('c',64)];
        $id=$this->ids['user_id']; $router=$this->router();
        $this->pdo->exec("UPDATE users SET status='suspended',archived_at=CURRENT_TIMESTAMP WHERE id=$id");
        $router->dispatch(Request::fake('POST',"/admin/usuarios/$id/status",['_token'=>str_repeat('c',64),'status'=>'active','reason'=>'Tentativa comum']));
        self::assertSame('suspended',$this->pdo->query("SELECT status FROM users WHERE id=$id")->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM system_logs WHERE event_code='admin.user_reactivated'")->fetchColumn());
        $router->dispatch(Request::fake('POST',"/admin/usuarios/$id/restaurar",['_token'=>str_repeat('c',64),'confirm'=>'RESTAURAR','reason'=>'Restauração explícita']));
        self::assertSame('active',$this->pdo->query("SELECT status FROM users WHERE id=$id")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT archived_at FROM users WHERE id=$id")->fetchColumn());
    }

    public function testBillingEnabledDashboardAndUserDetailShowScopedRealMoneyAndQuota():void
    {
        $this->seedBillingSummary();
        $_SESSION=['user_id'=>$this->ids['admin_id'],'_csrf'=>str_repeat('c',64)];
        $router=$this->router(null,true);
        $dashboard=$router->dispatch(Request::fake('GET','/admin'));
        self::assertSame(200,$dashboard->status());
        self::assertStringContainsString('Receita líquida total',$dashboard->body());
        self::assertStringContainsString('R$ 135,00',$dashboard->body());
        self::assertStringContainsString('Receita líquida mensal',$dashboard->body());
        $detail=$router->dispatch(Request::fake('GET','/admin/usuarios/'.$this->ids['user_id']));
        self::assertSame(200,$detail->status());
        self::assertStringContainsString('R$ 35,00',$detail->body());
        self::assertStringContainsString('3 / 30 min',$detail->body());
        self::assertStringContainsString('Pagamentos recentes',$detail->body());
        self::assertStringContainsString('Assinaturas recentes',$detail->body());
        self::assertStringContainsString('/admin/financeiro?user='.$this->ids['user_id'],$detail->body());
        self::assertStringNotContainsString('R$ 100,00',$detail->body());
        self::assertStringNotContainsString('private-provider-token',$detail->body());
        self::assertSame(10,substr_count($detail->body(),'data-billing-payment='));
        self::assertSame(10,substr_count($detail->body(),'data-billing-subscription='));
    }

    public function testBillingDisabledDoesNotRequireBillingOrQuotaTables():void
    {
        $_SESSION=['user_id'=>$this->ids['admin_id']];
        $router=$this->router();
        foreach(['/admin','/admin/usuarios/'.$this->ids['user_id']] as $path){
            $response=$router->dispatch(Request::fake('GET',$path));
            self::assertSame(200,$response->status());
            self::assertStringNotContainsString('Receita líquida total',$response->body());
        }
    }

    public function testBillingEnabledReadsRemainBehindAdministratorMiddleware():void
    {
        // No billing tables: an early financial read would fail before access denial.
        $router=$this->router(null,true);
        foreach(['/admin','/admin/usuarios/'.$this->ids['user_id']] as $path){
            $_SESSION=[];
            self::assertSame(302,$router->dispatch(Request::fake('GET',$path))->status());
            $_SESSION=['user_id'=>$this->ids['user_id']];
            self::assertSame(403,$router->dispatch(Request::fake('GET',$path))->status());
        }
    }

    private function seedBillingSummary():void
    {
        $this->pdo->exec('CREATE TABLE billing_payments(id INTEGER PRIMARY KEY,user_id INTEGER,plan_id INTEGER,provider TEXT,environment TEXT,status TEXT,currency TEXT,gross_amount_cents INTEGER,paid_amount_cents INTEGER,refunded_amount_cents INTEGER,paid_at TEXT,created_at TEXT,provider_payment_id TEXT)');
        $this->pdo->exec('CREATE TABLE billing_subscriptions(id INTEGER PRIMARY KEY,user_id INTEGER,plan_id INTEGER,provider TEXT,environment TEXT,status TEXT,currency TEXT,amount_cents INTEGER,current_period_ends_at TEXT,created_at TEXT)');
        $id=$this->ids['user_id']; $other=$this->ids['other_admin_id'];$plan=$this->ids['plan_id'];
        $this->pdo->exec("INSERT INTO billing_payments VALUES(1,$id,$plan,'stripe','production','paid','BRL',4000,4000,500,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,'private-provider-token'),(2,$other,$plan,'stripe','production','paid','BRL',10000,10000,0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,'private-provider-token'),(3,$id,$plan,'stripe','sandbox','paid','BRL',99999,99999,0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,'private-provider-token')");
        for($i=4;$i<=15;$i++) $this->pdo->exec("INSERT INTO billing_payments VALUES($i,$id,$plan,'stripe','production','pending','BRL',3500,0,0,NULL,CURRENT_TIMESTAMP,'private-provider-token')");
        for($i=1;$i<=12;$i++) $this->pdo->exec("INSERT INTO billing_subscriptions VALUES($i,$id,$plan,'stripe','production','active','BRL',3500,'2099-01-01',CURRENT_TIMESTAMP)");
        $this->pdo->exec('ALTER TABLE projects ADD COLUMN usage_recorded_at TEXT');
        $this->pdo->exec("INSERT INTO projects(user_id,name,status,processed_duration_seconds,usage_recorded_at) VALUES($id,'Media','completed',121,CURRENT_TIMESTAMP)");
        foreach(['output_file TEXT','output_size_bytes INTEGER','thumbnail TEXT','thumbnail_size_bytes INTEGER'] as $column) $this->pdo->exec('ALTER TABLE clips ADD COLUMN '.$column);
        $this->pdo->exec('CREATE TABLE user_brand_logos(user_id INTEGER,size_bytes INTEGER)');
        $this->pdo->exec('CREATE TABLE clip_thumbnails(user_id INTEGER,size_bytes INTEGER,status TEXT,object_key TEXT)');
    }

    private function router(?string $encryptionKey = null,bool $billing=false): Router
    {
        $router = new Router();
        $platformFeatures=['billing'=>$billing];
        $pdo = $this->pdo;
        $adminPdoFactory = static fn (): PDO => $pdo;
        $adminGeminiBaseline = [
            'api_key' => 'test-only', 'model' => 'gemini-2.5-flash',
            'base_url' => 'https://generativelanguage.googleapis.com',
            'http_timeout_seconds' => 30, 'response_limit_bytes' => 65536,
            'file_poll_seconds' => 15, 'validation_attempts' => 2, 'credits_per_minute' => 1,
        ];
        $adminEncryptionKey = $encryptionKey ?? base64_encode(str_repeat("\x35", 32));
        $adminGeminiTransportFactory = static fn (): GeminiTransport => new class implements GeminiTransport {
            public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds, int $responseLimitBytes): GeminiHttpResponse
            {
                return new GeminiHttpResponse(200, [], '{}');
            }
            public function upload(string $url, array $headers, string $absolutePath, int $sizeBytes, int $timeoutSeconds, int $responseLimitBytes): GeminiHttpResponse
            {
                throw new \LogicException('Not used.');
            }
        };

        require dirname(__DIR__, 2) . '/routes/admin.php';

        return $router;
    }
}
