<?php
declare(strict_types=1);
use App\Core\{Config,Database,Request,View};
use App\Controllers\{ThumbnailStudioController,PublicationPreparationController};
use App\Repositories\{ThumbnailRepository,PublicationPreparationRepository,UserRepository};
use App\Services\{ThumbnailStudioService,PublicationPreparationService,DatabaseJobDispatcher,RateLimiter};
use App\Storage\LocalPrivateStorage;
use App\Middleware\AuthMiddleware;
// Root may inject the library logo resolver/list and controller factories before including this module.
$thumbnailView=$sharedView??new View();
$thumbnailStudioControllerFactory=$thumbnailStudioControllerFactory??static function()use(&$thumbnailLogoResolver,&$thumbnailLogoList,$thumbnailView):ThumbnailStudioController{$pdo=Database::connection();$repo=new ThumbnailRepository($pdo);$users=new UserRepository($pdo);$limits=new RateLimiter($pdo);return new ThumbnailStudioController($thumbnailView,$repo,new ThumbnailStudioService($pdo,$repo,new DatabaseJobDispatcher($pdo),$thumbnailLogoResolver??null),new LocalPrivateStorage((string)Config::get('media.private_root'),2097152),static fn(int $u):bool=>$limits->hit('thumbnail_studio',(string)$u,20,3600),[$users,'findDashboardProfile'],$thumbnailLogoList??null);};
$publicationPreparationControllerFactory=$publicationPreparationControllerFactory??static function()use($thumbnailView):PublicationPreparationController{$pdo=Database::connection();$repo=new ThumbnailRepository($pdo);$users=new UserRepository($pdo);return new PublicationPreparationController($thumbnailView,$repo,new PublicationPreparationService($pdo,new PublicationPreparationRepository($pdo),$repo),[$users,'findDashboardProfile']);};
$thumbnailAuthenticated=$authenticated??new AuthMiddleware(static fn():UserRepository=>new UserRepository(Database::connection()));
$router->get('/clips/{id}/capas',static fn(Request $r,array $p)=>$thumbnailStudioControllerFactory()->show($r,$p),[$thumbnailAuthenticated]);
$router->post('/clips/{id}/capas',static fn(Request $r,array $p)=>$thumbnailStudioControllerFactory()->save($r,$p),[$thumbnailAuthenticated]);
$router->post('/clips/{id}/capas/gerar',static fn(Request $r,array $p)=>$thumbnailStudioControllerFactory()->generate($r,$p),[$thumbnailAuthenticated]);
$router->get('/clips/{id}/capas/status',static fn(Request $r,array $p)=>$thumbnailStudioControllerFactory()->status($r,$p),[$thumbnailAuthenticated]);
$router->get('/thumbnails/{id}',static fn(Request $r,array $p)=>$thumbnailStudioControllerFactory()->image($r,$p),[$thumbnailAuthenticated]);
$router->get('/thumbnails/{id}/download',static fn(Request $r,array $p)=>$thumbnailStudioControllerFactory()->download($r,$p),[$thumbnailAuthenticated]);
$router->get('/clips/{id}/publicacao',static fn(Request $r,array $p)=>$publicationPreparationControllerFactory()->show($r,$p),[$thumbnailAuthenticated]);
$router->post('/clips/{id}/publicacao',static fn(Request $r,array $p)=>$publicationPreparationControllerFactory()->store($r,$p),[$thumbnailAuthenticated]);
$router->post('/publicacoes/{id}/status',static fn(Request $r,array $p)=>$publicationPreparationControllerFactory()->status($r,$p),[$thumbnailAuthenticated]);
$router->get('/publicacoes/{id}/download',static fn(Request $r,array $p)=>$publicationPreparationControllerFactory()->download($r,$p),[$thumbnailAuthenticated]);
