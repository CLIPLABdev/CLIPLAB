<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Request;
use PHPUnit\Framework\TestCase;
final class PlatformCompositionTest extends TestCase
{
    public function testAllNewPrivateRoutesAreRegisteredAndDenyGuestsBeforeLoadingSecrets():void
    {
        $_SESSION=[];$router=require dirname(__DIR__,2).'/routes/web.php';
        foreach(['/perfil','/notificacoes','/preferencias','/conta/pagamentos','/checkout/plano/2','/admin/cupons','/admin/gateways','/admin/financeiro','/admin/emails','/admin/email-configuracao','/admin/campanhas'] as $path) {
            $response=$router->dispatch(Request::fake('GET',$path));
            self::assertSame(302,$response->status(),$path);
            self::assertSame('/login',$response->header('Location'),$path);
        }
    }
}
