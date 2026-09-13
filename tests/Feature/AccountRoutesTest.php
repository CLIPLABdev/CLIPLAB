<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AccountController;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Middleware\AuthMiddleware;
use App\Repositories\AccountRepository;
use App\Repositories\UserRepository;
use App\Services\PlanQuotaService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccountRoutesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->schema();
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAuthenticatedAccountPagesArePrivateReadOnlyAndOwnerScoped(): void
    {
        Session::put('user_id', 7);
        $router = $this->router();

        $plan = $router->dispatch(Request::fake('GET', '/conta/plano'));
        self::assertSame(200, $plan->status());
        self::assertSame('private, no-store', $plan->header('Cache-Control'));
        self::assertStringContainsString('Plano Pro', $plan->body());
        self::assertStringContainsString('R$ 19,90', $plan->body());
        self::assertStringContainsString('/assets/css/account.css', $plan->body());
        self::assertStringNotContainsString('action="/conta/plano"', $plan->body());

        $credits = $router->dispatch(Request::fake('GET', '/conta/creditos?filter=refunds&page=1&user_id=8'));
        self::assertSame(200, $credits->status());
        self::assertSame('private, no-store', $credits->header('Cache-Control'));
        self::assertStringContainsString('Reembolso da análise', $credits->body());
        self::assertStringNotContainsString('Bônus inicial', $credits->body());
        self::assertStringNotContainsString('Lançamento privado', $credits->body());

        $post = $router->dispatch(Request::fake('POST', '/conta/plano'));
        self::assertSame(405, $post->status());
    }

    public function testInvalidLedgerFilterAndPageFallBackToAllowlistedDefaults(): void
    {
        Session::put('user_id', 7);
        $response = $this->router()->dispatch(new Request('GET', '/conta/creditos', [
            'filter' => ['refunds'],
            'page' => str_repeat('9', 100),
        ]));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Bônus inicial', $response->body());
        self::assertStringContainsString('Reembolso da análise', $response->body());
    }

    public function testGuestIsRedirectedBeforeAccountDataIsRead(): void
    {
        $response = $this->router()->dispatch(Request::fake('GET', '/conta/plano'));

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    private function router(): Router
    {
        $router = new Router();
        $controller = new AccountController(
            new View(),
            new PlanQuotaService($this->pdo),
            new AccountRepository($this->pdo)
        );
        $register = require dirname(__DIR__, 2) . '/routes/account.php';
        $register($router, $controller, new AuthMiddleware(new UserRepository($this->pdo)));

        return $router;
    }

    private function schema(): void
    {
        $this->pdo->exec('CREATE TABLE user_brand_logos (id INTEGER PRIMARY KEY, user_id INTEGER, size_bytes INTEGER)');
        $this->pdo->exec('CREATE TABLE clip_thumbnails (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, object_key TEXT NULL, size_bytes INTEGER)');
        $this->pdo->exec('CREATE TABLE plans (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, price_cents INTEGER, monthly_minutes INTEGER, credits INTEGER, features TEXT, is_active INTEGER)');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, plan_id INTEGER, credits INTEGER, status TEXT)');
        $this->pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER, processed_duration_seconds INTEGER DEFAULT 0, usage_recorded_at TEXT NULL)');
        $this->pdo->exec('CREATE TABLE project_sources (id INTEGER PRIMARY KEY, project_id INTEGER, object_key TEXT NULL, size_bytes INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE clips (id INTEGER PRIMARY KEY, project_id INTEGER, output_file TEXT NULL, output_size_bytes INTEGER NULL, thumbnail TEXT NULL, thumbnail_size_bytes INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE credit_transactions (id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT, amount INTEGER, balance_after INTEGER, reference_type TEXT NULL, reference_id INTEGER NULL, description TEXT NULL, created_at TEXT)');
    }

    private function fixtures(): void
    {
        $free = json_encode(['exports_hd' => false, 'priority_processing' => false, 'team_access' => false, 'limits' => ['max_upload_bytes' => 100, 'storage_bytes' => 1000]], JSON_THROW_ON_ERROR);
        $pro = json_encode(['exports_hd' => true, 'priority_processing' => true, 'team_access' => false, 'limits' => ['max_upload_bytes' => 500, 'storage_bytes' => 2000]], JSON_THROW_ON_ERROR);
        $insertPlan = $this->pdo->prepare('INSERT INTO plans VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
        $insertPlan->execute([1, 'free', 'Free', 0, 30, 10, $free]);
        $insertPlan->execute([2, 'pro', 'Pro', 1990, 300, 150, $pro]);
        $this->pdo->exec("INSERT INTO users VALUES (7, 'Ana', 'ana@example.test', 2, 9, 'active'), (8, 'Bia', 'bia@example.test', 1, 99, 'active')");
        $this->pdo->exec("INSERT INTO credit_transactions VALUES
            (1, 7, 'credit', 10, 10, 'registration', NULL, 'Bônus inicial', '2026-09-01 10:00:00'),
            (2, 7, 'debit', 2, 8, 'credit_reservation', 4, 'Consumo da análise', '2026-09-02 10:00:00'),
            (3, 7, 'credit', 2, 10, 'credit_reservation', 4, 'Reembolso da análise', '2026-09-03 10:00:00'),
            (4, 8, 'credit', 99, 99, 'registration', NULL, 'Lançamento privado', '2026-09-04 10:00:00')");
    }
}
