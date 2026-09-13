<?php
declare(strict_types=1);

use App\Controllers\EditorLibraryController;
use App\Core\{Config, Database, Request, View};
use App\Middleware\AuthMiddleware;
use App\Repositories\{EditorLibraryRepository, UserRepository};
use App\Services\{EditorLibraryService, PlanQuotaService, RateLimiter};
use App\Storage\LocalPrivateStorage;

// Lazy and injectable: composition itself never opens a database connection.
$editorLibraryView = $sharedView ?? new View();
$editorLibraryControllerFactory = $editorLibraryControllerFactory ?? static function () use ($editorLibraryView): EditorLibraryController {
    $pdo = Database::connection(); $media = (array) Config::get('media', []);
    $repository = new EditorLibraryRepository($pdo); $quota = new PlanQuotaService($pdo);
    $storage = new LocalPrivateStorage((string) ($media['private_root'] ?? ''), 2097152);
    $service = new EditorLibraryService($repository, $storage, [$quota, 'assertAdditionalStorageAvailable']);
    $users = new UserRepository($pdo); $rate = new RateLimiter($pdo);
    return new EditorLibraryController($editorLibraryView, $service, [$users, 'findDashboardProfile'],
        static fn (int $userId): bool => $rate->hit('editor_library_write', (string) $userId, 30, 60));
};
$editorLibraryAuthenticated = $authenticated ?? new AuthMiddleware(static fn (): UserRepository => new UserRepository(Database::connection()));
$router->get('/api/editor-library', static fn (Request $request) => $editorLibraryControllerFactory()->catalog($request), [$editorLibraryAuthenticated]);
$router->get('/templates', static fn (Request $request) => $editorLibraryControllerFactory()->templates($request), [$editorLibraryAuthenticated]);
$router->post('/templates', static fn (Request $request) => $editorLibraryControllerFactory()->storeTemplates($request), [$editorLibraryAuthenticated]);
$router->get('/marca', static fn (Request $request) => $editorLibraryControllerFactory()->brand($request), [$editorLibraryAuthenticated]);
$router->post('/marca', static fn (Request $request) => $editorLibraryControllerFactory()->storeBrand($request), [$editorLibraryAuthenticated]);
$router->post('/marca/logo', static fn (Request $request) => $editorLibraryControllerFactory()->uploadLogo($request), [$editorLibraryAuthenticated]);
$router->get('/marca/logos/{id}', static fn (Request $request, array $parameters) => $editorLibraryControllerFactory()->logo($request, $parameters), [$editorLibraryAuthenticated]);
