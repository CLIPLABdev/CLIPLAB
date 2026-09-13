<?php
declare(strict_types=1);
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use App\Core\{Request,Session,Router,Csrf,View};
use App\Middleware\AuthMiddleware;
use App\Repositories\{UserRepository,ThumbnailRepository,PublicationPreparationRepository};
use App\Controllers\{ThumbnailStudioController,PublicationPreparationController};
use App\Services\{ThumbnailStudioService,PublicationPreparationService};
use App\Contracts\JobDispatcher;
use App\Storage\LocalPrivateStorage;
use Tests\Support\ThumbnailTestDatabase;
final class ThumbnailStudioRoutesTest extends TestCase
{
 public function test_private_routes_enforce_real_auth_csrf_and_ownership():void {
  $pdo=ThumbnailTestDatabase::create();$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT);INSERT INTO users VALUES(7,'active'),(8,'active'),(9,'suspended')");$repo=new ThumbnailRepository($pdo);$jobs=new class implements JobDispatcher {public function dispatch(string $type,int $projectId,array $payload,string $idempotencyKey):int{return 1;}};$studio=new ThumbnailStudioController(new View(),$repo,new ThumbnailStudioService($pdo,$repo,$jobs),new LocalPrivateStorage(sys_get_temp_dir(),100000),static fn(int $u):bool=>true);$pub=new PublicationPreparationController(new View(),$repo,new PublicationPreparationService($pdo,new PublicationPreparationRepository($pdo),$repo));$thumbnailStudioControllerFactory=static fn()=>$studio;$publicationPreparationControllerFactory=static fn()=>$pub;$authenticated=new AuthMiddleware(new UserRepository($pdo));$router=new Router();require dirname(__DIR__,2).'/routes/thumbnail-studio.php';
  Session::forget('user_id');self::assertSame('/login',$router->dispatch(Request::fake('GET','/clips/1/capas'))->header('Location'));Session::put('user_id',9);self::assertSame('/login',$router->dispatch(Request::fake('GET','/clips/1/publicacao'))->header('Location'));Session::put('user_id',8);self::assertSame(404,$router->dispatch(Request::fake('GET','/clips/1/capas'))->status());Session::put('user_id',7);self::assertSame(419,$router->dispatch(Request::fake('POST','/clips/1/capas/gerar',['request_key'=>str_repeat('c',64)]))->status());self::assertSame(302,$router->dispatch(Request::fake('POST','/clips/1/capas/gerar',['request_key'=>str_repeat('c',64),'_token'=>Csrf::token()]))->status());self::assertSame(200,$router->dispatch(Request::fake('GET','/clips/1/capas/status'))->status());self::assertSame(404,$router->dispatch(Request::fake('GET','/thumbnails/999/download'))->status());
 }
}
