<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ClipAssetController;
use App\Controllers\ClipLibraryController;
use App\Controllers\ClipRenderController;
use App\Controllers\ClipSourcePreviewController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\MediaPipeConsentController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\ProjectController;
use App\Controllers\ProjectSuggestionController;
use App\Controllers\ProjectStatusController;
use App\Contracts\PrivateStorage;
use App\Contracts\ProjectCreator;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Media\DirectUrlValidator;
use App\Media\PinnedHttpDownloader;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Media\UploadValidator;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ClipLibraryRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\PlanRepository;
use App\Repositories\AccountRepository;
use App\Repositories\MarketingContentRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserConsentRepository;
use App\Services\AuthService;
use App\Services\ClipRenderRequestService;
use App\Services\ClipStatusService;
use App\Services\DashboardService;
use App\Services\DatabaseJobDispatcher;
use App\Services\MarketingContentService;
use App\Services\MediaPipeConsentService;
use App\Services\PasswordResetService;
use App\Services\PrivateFileResponseFactory;
use App\Services\PrivateRangeResponseFactory;
use App\Services\ProjectIntakeService;
use App\Services\ProjectStatusService;
use App\Services\RateLimiter;
use App\Storage\LocalPrivateStorage;

$router = new Router();
$platform = require dirname(__DIR__).'/bootstrap/platform.php';
$sharedView = $platform['view'];
$platformFeatures = $platform['features'];
$communications = $platform['communications'];
$platformBaseUrl = $platform['base_url'];
$authService = static function () use ($communications, $platformBaseUrl): AuthService {
    $pdo = Database::connection();

    return new AuthService($pdo, new UserRepository($pdo), new PlanRepository($pdo), $communications,
        new \App\Communications\EmailVerificationService($pdo, $communications, $platformBaseUrl));
};
$rateLimiter = static function (): RateLimiter {
    return new RateLimiter(Database::connection());
};
$auth = new AuthController($sharedView, $authService, $rateLimiter);
$passwordResetService = static function () use ($communications, $platformBaseUrl): PasswordResetService {
    $pdo = Database::connection();

    return new PasswordResetService(
        new UserRepository($pdo),
        new PasswordResetRepository($pdo),
        null,
        $platformBaseUrl,
        $communications
    );
};
$passwordReset = new PasswordResetController($sharedView, $passwordResetService, $rateLimiter);
$home = new HomeController($sharedView, static function (): MarketingContentService {
    $pdo = Database::connection();

    return new MarketingContentService(new AccountRepository($pdo), new MarketingContentRepository($pdo));
});
$dashboardService = static function (): DashboardService {
    $pdo = Database::connection();

    return new DashboardService(new ProjectRepository($pdo), new CreditTransactionRepository($pdo), new UserRepository($pdo), null,
        [new \App\Services\PlanQuotaService($pdo), 'snapshotForUser']);
};
$userRepository = static function (): UserRepository {
    return new UserRepository(Database::connection());
};
$projectRepository = static function (): ProjectRepository {
    return new ProjectRepository(Database::connection());
};
$clipRepository = static function (): ClipRepository {
    return new ClipRepository(Database::connection());
};
$clipLibraryRepository = static function (): ClipLibraryRepository {
    return new ClipLibraryRepository(Database::connection());
};
$media = (array) Config::get('media', []);
$maxBytes = (int) ($media['effective_upload_bytes'] ?? $media['max_upload_bytes'] ?? 524288000);
$storage = static function () use ($media, $maxBytes): PrivateStorage {
    return new LocalPrivateStorage((string) ($media['private_root'] ?? ''), $maxBytes);
};
$projectCreator = static function () use ($media, $maxBytes, $storage, $projectRepository): ProjectCreator {
    $pdo = Database::connection();

    return new ProjectIntakeService(
        $pdo,
        $projectRepository(),
        new ProjectSourceRepository($pdo),
        $storage(),
        new DatabaseJobDispatcher($pdo, 'media', (int) ($media['queue']['max_attempts'] ?? 3)),
        new UploadValidator($maxBytes),
        new DirectUrlValidator(),
        new \App\Services\PlanQuotaService($pdo),
        new \App\Repositories\SourceArtifactCleanupRepository($pdo)
    );
};
$dashboard = new DashboardController($sharedView, $dashboardService, $userRepository);
$profile = new ProfileController($sharedView, $userRepository,
    static fn () => new \App\Account\ProfileAccountService(Database::connection(), $communications, $platformBaseUrl),
    static fn () => new \App\Account\ProfileAvatarService(Database::connection(), rtrim((string) Config::get('media.private_root'), '/\\').'/avatars'),
    $rateLimiter
);
$mediaPipeConsentService = static function (): MediaPipeConsentService {
    $pdo = Database::connection();

    return new MediaPipeConsentService(new UserConsentRepository($pdo));
};
$mediaPipeConsent = static fn (): MediaPipeConsentController => new MediaPipeConsentController(
    $mediaPipeConsentService(),
    static fn (int $projectId, int $userId): bool =>
        $projectRepository()->detailForOwnedProject($projectId, $userId) !== null
);
$projects = new ProjectController(
    $sharedView,
    $projectCreator,
    static fn (int $userId, int $limit): array => $projectRepository()->listForUser($userId, $limit),
    static fn (int $userId): ?array => $userRepository()->findDashboardProfile($userId),
    $maxBytes,
    static fn (int $userId): int => (int) (new \App\Services\PlanQuotaService(Database::connection()))
        ->snapshotForUser($userId)['plan']['features']['limits']['max_upload_bytes']
);
$clipLibrary = new ClipLibraryController(
    $sharedView,
    static fn (int $userId, string $filter, int $page, int $perPage, ?int $projectId = null): array =>
        $clipLibraryRepository()->paginateForUser($userId, $filter, $page, $perPage, $projectId),
    static fn (int $userId): ?array => $userRepository()->findDashboardProfile($userId)
);
$projectStatus = new ProjectStatusController(new ProjectStatusService(
    static fn (int $projectId, int $userId): ?array => $projectRepository()->statusForOwnedProject($projectId, $userId)
));
$analysisRecovery = static fn (): \App\Services\AiAnalysisRecoveryService => new \App\Services\AiAnalysisRecoveryService(
    Database::connection(), (int) Config::get('gemini.credits_per_minute',1),
    max(1,min(8,(int) Config::get('gemini.analysis_max_attempts',6)))
);
$projectSuggestions = new ProjectSuggestionController(
    $sharedView,
    static fn (int $projectId, int $userId): ?array => $projectRepository()->detailForOwnedProject($projectId, $userId),
    static fn (int $projectId, int $userId): array => $clipRepository()->suggestionsForOwnedProject($projectId, $userId),
    static fn (int $userId): ?array => $userRepository()->findDashboardProfile($userId),
    null,
    static fn (int $userId): bool => $mediaPipeConsentService()->hasActive($userId),
    [
        'reframe_max_duration_seconds' => $media['reframe_max_duration_seconds'] ?? null,
        'reframe_preview_max_frames' => $media['reframe_preview_max_frames'] ?? null,
        'reframe_preview_max_edge' => $media['reframe_preview_max_edge'] ?? null,
    ],
    static fn (int $projectId,int $userId): ?int => $analysisRecovery()->recoveryToken($projectId,$userId)
);
$projectAnalysisResume = new \App\Controllers\ProjectAnalysisResumeController(static function (int $projectId, int $userId, ?int $expectedRefundId = null) use ($media,$analysisRecovery): ?string {
    $pdo = Database::connection();
    $resume = new \App\Services\ProjectAnalysisResumeService($pdo, static function (?string $existingModel) use ($pdo, $media): \App\Contracts\AiPipelineScheduler {
        $gemini = (new \App\Services\AdminGeminiSettingsService(
            $pdo, (array) Config::get('gemini', []), (string) \App\Core\Env::get('APP_ENCRYPTION_KEY', '')
        ))->effective();
        $model = $existingModel ?? (string) ($gemini['model'] ?? '');
        return new \App\Services\AiPipelineStarter(
            $pdo, new ProjectRepository($pdo), new ProjectSourceRepository($pdo),
            new \App\Repositories\AiAnalysisRepository($pdo),
            new \App\Services\CreditReservationService($pdo,
                new \App\Repositories\CreditReservationRepository($pdo), new CreditTransactionRepository($pdo),
                (int) ($gemini['credits_per_minute'] ?? 1)),
            new DatabaseJobDispatcher($pdo, 'media', max(1,min(8,(int) ($gemini['analysis_max_attempts'] ?? 6)))),
            \App\Ai\ViralClipPrompt::VERSION, trim($model) === '' ? 'unconfigured' : $model,
            new \App\Services\PlanQuotaService($pdo)
        );
    },$analysisRecovery());
    return $resume->resume($projectId, $userId, $expectedRefundId);
});
$clipStatuses = new ClipStatusService(
    static fn (int $clipId, int $userId): ?array => $clipRepository()->statusForOwnedClip($clipId, $userId),
    static fn (int $clipId, int $userId): ?string => (new \App\Repositories\ClipSubtitleStatusRepository(Database::connection()))->forOwnedClip($clipId, $userId)
);
$requestClipRender = static function (
    int $clipId,
    int $userId,
    string $start,
    string $end,
    ReframeSubmission $reframe
) use ($media) {
    $pdo = Database::connection();
    $service = new ClipRenderRequestService(
        $pdo,
        new ClipRepository($pdo),
        new ProjectRepository($pdo),
        new ClipRenderProfileRepository($pdo),
        new ReframePlanValidator(
            (int) ($media['reframe_max_duration_seconds'] ?? 90),
            (int) ($media['reframe_max_keyframes'] ?? 32)
        ),
        new DatabaseJobDispatcher($pdo, 'media', (int) ($media['queue']['max_attempts'] ?? 3)),
        (int) ($media['render_max_duration_seconds'] ?? 180),
        \App\Services\SourceDurationPreflight::local($pdo,$media)
    );

    return $service->request($clipId, $userId, $start, $end, $reframe);
};
$clipRender = new ClipRenderController($requestClipRender, $clipStatuses);
$clipAssets = new ClipAssetController(
    static fn (int $clipId, int $userId, string $kind): ?array => $clipRepository()->artifactForOwnedClip($clipId, $userId, $kind),
    $storage,
    new PrivateFileResponseFactory(),
    null,
    new PrivateRangeResponseFactory()
);
$clipSourcePreview = new ClipSourcePreviewController(
    static fn (int $clipId, int $userId): ?array => $clipRepository()->sourceForOwnedPreview($clipId, $userId),
    $storage,
    new PrivateRangeResponseFactory()
);
$authenticated = new AuthMiddleware($userRepository, static function (int $userId): bool {
    $identity = (new \App\Repositories\AdminRepository(Database::connection()))->findIdentity($userId);
    return ($identity['role'] ?? null) === 'admin' && ($identity['status'] ?? null) === 'active';
});

$router->get('/', static fn () => $home->index());
$router->get('/login', static fn () => $auth->showLogin(), [GuestMiddleware::class]);
$router->post('/login', static fn (Request $request) => $auth->login($request), [GuestMiddleware::class]);
$router->get('/cadastro', static fn () => $auth->showRegister(), [GuestMiddleware::class]);
$router->post('/cadastro', static fn (Request $request) => $auth->register($request), [GuestMiddleware::class]);
$router->post('/logout', static fn () => $auth->logout(), [$authenticated]);
$router->get('/dashboard', static fn () => $dashboard->index(), [$authenticated]);
$router->get('/perfil', static fn () => $profile->edit(), [$authenticated]);
$router->post('/perfil', static fn (Request $request) => $profile->update($request), [$authenticated]);
$router->post('/perfil/senha', static fn (Request $request) => $profile->updatePassword($request), [$authenticated]);
$router->get('/perfil/confirmar-email', static fn (Request $request) => $profile->confirmEmailForm($request), [$authenticated]);
$router->post('/perfil/confirmar-email', static fn (Request $request) => $profile->confirmEmail($request), [$authenticated]);
$router->post('/perfil/email/cancelar', static fn (Request $request) => $profile->cancelEmailChange($request), [$authenticated]);
$router->get('/perfil/avatar', static fn () => $profile->avatar(), [$authenticated]);
$router->post('/perfil/avatar', static fn (Request $request) => $profile->storeAvatar($request), [$authenticated]);
$router->post('/perfil/avatar/remover', static fn (Request $request) => $profile->removeAvatar($request), [$authenticated]);
$router->get('/projetos', static fn () => $projects->index(), [$authenticated]);
$router->get('/projetos/novo', static fn () => $projects->create(), [$authenticated]);
$router->get('/projetos/{id}', static fn (Request $request, array $parameters) => $projectSuggestions->show($request, $parameters), [$authenticated]);
$router->post('/projetos/{id}/retomar-analise', static fn (Request $request, array $parameters) => $projectAnalysisResume->store($request, $parameters), [$authenticated]);
$router->post('/projetos', static fn (Request $request) => $projects->store($request), [$authenticated]);
$router->get('/clips', static fn (Request $request) => $clipLibrary->index($request), [$authenticated]);
$router->post('/privacidade/consentimentos/mediapipe', static fn (Request $request) => $mediaPipeConsent()->grant($request), [$authenticated]);
$router->post('/privacidade/consentimentos/mediapipe/revogar', static fn (Request $request) => $mediaPipeConsent()->revoke($request), [$authenticated]);
$router->post('/clips/{id}/render', static fn (Request $request, array $parameters) => $clipRender->store($request, $parameters), [$authenticated]);
$router->get('/api/projects/{id}/status', static fn (Request $request, array $parameters) => $projectStatus->show($request, $parameters), [$authenticated]);
$router->get('/api/clips/{id}/status', static fn (Request $request, array $parameters) => $clipRender->status($request, $parameters), [$authenticated]);
$router->get('/clips/{id}/thumbnail', static fn (Request $request, array $parameters) => $clipAssets->thumbnail($request, $parameters), [$authenticated]);
$router->get('/clips/{id}/download', static fn (Request $request, array $parameters) => $clipAssets->download($request, $parameters), [$authenticated]);
$router->get('/clips/{id}/preview', static fn (Request $request, array $parameters) => $clipAssets->preview($request, $parameters), [$authenticated]);
$router->get('/clips/{id}/source-preview', static fn (Request $request, array $parameters) => $clipSourcePreview->show($request, $parameters), [$authenticated]);
$router->get('/esqueci-minha-senha', static fn () => $passwordReset->showForgotPassword(), [GuestMiddleware::class]);
$router->post('/esqueci-minha-senha', static fn (Request $request) => $passwordReset->requestReset($request), [GuestMiddleware::class]);
$router->get('/redefinir-senha', static fn (Request $request) => $passwordReset->showResetPassword($request), [GuestMiddleware::class]);
$router->post('/redefinir-senha', static fn (Request $request) => $passwordReset->reset($request), [GuestMiddleware::class]);

$registerAccount = require __DIR__ . '/account.php';
$registerAccount($router, new \App\Controllers\AccountController(
    $sharedView,
    static fn (): \App\Services\PlanQuotaService => new \App\Services\PlanQuotaService(Database::connection()),
    static fn (): \App\Repositories\AccountRepository => new \App\Repositories\AccountRepository(Database::connection())
), $authenticated);
require __DIR__ . '/editor.php';
require __DIR__ . '/editor-library.php';
$thumbnailLogoResolver = static fn (int $assetId, int $projectId): ?string =>
    (new \App\Repositories\EditorLibraryRepository(Database::connection()))->resolveLogoForProject($assetId, $projectId);
$thumbnailLogoList = static fn (int $userId): array => array_map(
    static fn (array $logo): array => ['id' => (int) $logo['id'], 'url' => '/marca/logos/' . (int) $logo['id']],
    (new \App\Repositories\EditorLibraryRepository(Database::connection()))->listLogosOwned($userId)
);
require __DIR__ . '/thumbnail-studio.php';
require __DIR__ . '/admin.php';
$registerPlatform = require __DIR__.'/platform.php';
$registerPlatform($router,$platform+['authenticated'=>$authenticated,'admin_only'=>$adminOnly]);
require __DIR__ . '/informational.php';

return $router;
