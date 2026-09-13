<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\MarketingContentController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Gemini\CurlGeminiTransport;
use App\Gemini\GeminiTransport;
use App\Middleware\AdminMiddleware;
use App\Repositories\AdminRepository;
use App\Repositories\AccountRepository;
use App\Repositories\MarketingContentRepository;
use App\Repositories\SystemLogRepository;
use App\Services\AdminGeminiSettingsService;
use App\Services\AdminService;
use App\Services\MarketingContentService;
use App\Services\RateLimiter;
use App\Services\PlatformSettingsService;
use App\Services\PlatformBrandAssetService;
use App\Services\PromotionService;
use App\Repositories\PlatformSettingsRepository;
use App\Repositories\PromotionRepository;

if (!isset($router) || !$router instanceof Router) {
    throw new RuntimeException('The admin routes require the application router.');
}

/** @var callable():\PDO $adminPdoFactory */
$adminPdoFactory ??= static fn (): \PDO => Database::connection();
/** @var array<string,mixed> $adminGeminiBaseline */
$adminGeminiBaseline ??= (array) Config::get('gemini', []);
$adminEncryptionKey ??= (string) Env::get('APP_ENCRYPTION_KEY', '');
$sharedView ??= new View();
/** @var callable():GeminiTransport $adminGeminiTransportFactory */
$adminGeminiTransportFactory ??= static fn (): GeminiTransport => new CurlGeminiTransport();

$adminRepositoryFactory = static fn (): AdminRepository => new AdminRepository($adminPdoFactory());
$adminBillingEnabled = !empty($platformFeatures['billing']);
$adminControllerFactory = static function () use (
    $adminPdoFactory,
    $adminGeminiBaseline,
    $adminEncryptionKey,
    $adminGeminiTransportFactory,
    $sharedView,
    $adminBillingEnabled
): AdminController {
    $pdo = $adminPdoFactory();
    $repository = new AdminRepository($pdo);
    $logs = new SystemLogRepository($pdo);

    return new AdminController(
        $sharedView,
        $repository,
        $logs,
        new AdminService($pdo, $repository, $logs),
        new AdminGeminiSettingsService($pdo, $adminGeminiBaseline, $adminEncryptionKey, $adminGeminiTransportFactory(), new RateLimiter($pdo), $logs),
        new PlatformSettingsService(new PlatformSettingsRepository($pdo)),
        new PromotionService(new PromotionRepository($pdo), new \DateTimeImmutable('now',new \DateTimeZone('UTC'))),
        new PlatformBrandAssetService(dirname(__DIR__).'/public/assets/images'),
        $adminBillingEnabled ? new \App\Billing\FinancialRepository($pdo) : null,
        $adminBillingEnabled ? static fn (): \App\Services\PlanQuotaService => new \App\Services\PlanQuotaService($pdo) : null
    );
};
$marketingContentControllerFactory = static function () use ($adminPdoFactory, $sharedView): MarketingContentController {
    $pdo = $adminPdoFactory();

    return new MarketingContentController(
        $sharedView,
        new AdminRepository($pdo),
        new MarketingContentService(new AccountRepository($pdo), new MarketingContentRepository($pdo))
    );
};
$adminOnly = new AdminMiddleware(static fn (int $userId): ?array => $adminRepositoryFactory()->findIdentity($userId));

$router->get('/admin', static fn (Request $request) => $adminControllerFactory()->dashboard($request), [$adminOnly]);
$router->get('/admin/usuarios', static fn (Request $request) => $adminControllerFactory()->users($request), [$adminOnly]);
$router->get('/admin/usuarios/{id}', static fn (Request $request, array $parameters) => $adminControllerFactory()->user($request, $parameters), [$adminOnly]);
$router->get('/admin/projetos', static fn (Request $request) => $adminControllerFactory()->projects($request), [$adminOnly]);
$router->get('/admin/videos', static fn (Request $request) => $adminControllerFactory()->videos($request), [$adminOnly]);
$router->get('/admin/jobs', static fn (Request $request) => $adminControllerFactory()->jobs($request), [$adminOnly]);
$router->get('/admin/erros', static fn (Request $request) => $adminControllerFactory()->errors($request), [$adminOnly]);
$router->get('/admin/creditos', static fn (Request $request) => $adminControllerFactory()->credits($request), [$adminOnly]);
$router->get('/admin/planos', static fn (Request $request) => $adminControllerFactory()->plans($request), [$adminOnly]);
$router->get('/admin/logs', static fn (Request $request) => $adminControllerFactory()->logs($request), [$adminOnly]);
$router->get('/admin/configuracoes/gemini', static fn (Request $request) => $adminControllerFactory()->gemini($request), [$adminOnly]);
$router->get('/admin/configuracoes', static fn (Request $request) => $adminControllerFactory()->settings($request), [$adminOnly]);
$router->get('/admin/promocoes', static fn (Request $request) => $adminControllerFactory()->promotions($request), [$adminOnly]);
$router->get('/admin/conteudo', static fn (Request $request) => $marketingContentControllerFactory()->edit($request), [$adminOnly]);

$router->post('/admin/usuarios/{id}/status', static fn (Request $request, array $parameters) => $adminControllerFactory()->changeUserStatus($request, $parameters), [$adminOnly]);
$router->post('/admin/usuarios', static fn (Request $request) => $adminControllerFactory()->createUser($request), [$adminOnly]);
$router->post('/admin/usuarios/{id}', static fn (Request $request, array $parameters) => $adminControllerFactory()->editUser($request, $parameters), [$adminOnly]);
$router->post('/admin/usuarios/{id}/arquivar', static fn (Request $request, array $parameters) => $adminControllerFactory()->archiveUser($request, $parameters), [$adminOnly]);
$router->post('/admin/usuarios/{id}/restaurar', static fn (Request $request, array $parameters) => $adminControllerFactory()->restoreUser($request, $parameters), [$adminOnly]);
$router->post('/admin/usuarios/{id}/plano', static fn (Request $request, array $parameters) => $adminControllerFactory()->assignPlan($request, $parameters), [$adminOnly]);
$router->post('/admin/creditos/ajustar', static fn (Request $request) => $adminControllerFactory()->adjustCredits($request), [$adminOnly]);
$router->post('/admin/planos/{id}', static fn (Request $request, array $parameters) => $adminControllerFactory()->updatePlan($request, $parameters), [$adminOnly]);
$router->post('/admin/planos', static fn (Request $request) => $adminControllerFactory()->createPlan($request), [$adminOnly]);
$router->post('/admin/configuracoes/gemini', static fn (Request $request) => $adminControllerFactory()->saveGemini($request), [$adminOnly]);
$router->post('/admin/configuracoes/gemini/testar', static fn (Request $request) => $adminControllerFactory()->testGemini($request), [$adminOnly]);
$router->post('/admin/configuracoes', static fn (Request $request) => $adminControllerFactory()->saveSettings($request), [$adminOnly]);
$router->post('/admin/promocoes', static fn (Request $request) => $adminControllerFactory()->createPromotion($request), [$adminOnly]);
$router->post('/admin/promocoes/{id}', static fn (Request $request,array $parameters) => $adminControllerFactory()->updatePromotion($request,$parameters), [$adminOnly]);
$router->post('/admin/promocoes/{id}/excluir', static fn (Request $request,array $parameters) => $adminControllerFactory()->deletePromotion($request,$parameters), [$adminOnly]);
$router->post('/admin/conteudo', static fn (Request $request) => $marketingContentControllerFactory()->save($request), [$adminOnly]);
