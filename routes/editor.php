<?php

declare(strict_types=1);

use App\Controllers\ClipEditorController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Media\Reframe\ReframePlanValidator;
use App\Middleware\AuthMiddleware;
use App\Repositories\ClipEditorRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UserConsentRepository;
use App\Repositories\UserRepository;
use App\Services\ClipEditService;
use App\Services\DatabaseJobDispatcher;
use App\Services\MediaPipeConsentService;
use App\Services\RateLimiter;

// Included by routes/web.php with its existing router. No connection until an authenticated handler runs.
$editorView=$sharedView ?? new View();
$editorControllerFactory=$editorControllerFactory ?? static function () use ($editorView): ClipEditorController {
    $pdo=Database::connection();
    $media=(array)Config::get('media',[]);
    $repository=new ClipEditorRepository($pdo);
    $profiles=new ClipRenderProfileRepository($pdo);
    $service=new ClipEditService($pdo,$repository,new ClipRepository($pdo),new ProjectRepository($pdo),$profiles,
        new ReframePlanValidator((int)($media['reframe_max_duration_seconds'] ?? 90),(int)($media['reframe_max_keyframes'] ?? 32)),
        new DatabaseJobDispatcher($pdo,'media',(int)($media['queue']['max_attempts'] ?? 3)),
        (int)($media['render_max_duration_seconds'] ?? 180),
        static fn (int $assetId, int $userId): bool => (new \App\Repositories\EditorLibraryRepository($pdo))->findLogoOwned($assetId, $userId) !== null,
        \App\Services\SourceDurationPreflight::local($pdo,$media));
    $users=new UserRepository($pdo);
    $consent=new MediaPipeConsentService(new UserConsentRepository($pdo));
    $limits=new RateLimiter($pdo);
    return new ClipEditorController($editorView,[$repository,'findOwned'],[$repository,'snapshot'],$service,
        [$users,'findDashboardProfile'],[$consent,'hasActive'],
        static fn (int $userId): bool => $limits->hit('clip_editor_export',(string)$userId,20,3600),
        [$profiles,'findForClipRevision'],$media);
};
$editorAuthenticated=$authenticated ?? new AuthMiddleware(static fn (): UserRepository => new UserRepository(Database::connection()));
$router->get('/clips/{id}/editar',static fn (Request $request,array $parameters) => $editorControllerFactory()->show($request,$parameters),[$editorAuthenticated]);
$router->post('/clips/{id}/editar',static fn (Request $request,array $parameters) => $editorControllerFactory()->store($request,$parameters),[$editorAuthenticated]);
$router->get('/clips/{id}/legendas.srt',static fn (Request $request,array $parameters) => $editorControllerFactory()->subtitles($request,$parameters),[$editorAuthenticated]);
