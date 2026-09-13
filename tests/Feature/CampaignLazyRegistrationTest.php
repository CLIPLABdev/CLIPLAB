<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\{Request,Response,Router};
use PHPUnit\Framework\TestCase;
final class CampaignLazyRegistrationTest extends TestCase
{
    public function testGuestDenialDoesNotResolveDatabaseOrCipher():void
    {
        $count=0;$blocked=static function()use(&$count){$count++;throw new \RuntimeException('Must not resolve');};
        $register=require dirname(__DIR__,2).'/routes/campaigns.php';$router=new Router();
        $register($router,['pdo'=>$blocked,'cipher'=>$blocked,'admin_only'=>static fn()=>Response::redirect('/login')]);
        self::assertSame(0,$count);
        self::assertSame('/login',$router->dispatch(Request::fake('GET','/admin/campanhas'))->header('Location'));
        self::assertSame(419,$router->dispatch(Request::fake('POST','/admin/campanhas'))->status());
        self::assertSame(0,$count);
    }
}
