<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Core\Csrf;
use PHPUnit\Framework\TestCase;
final class DeploymentAccessFinishTest extends TestCase
{
 protected function setUp():void{$_SESSION=['_csrf'=>str_repeat('a',64)];}
 protected function tearDown():void{$_SESSION=[];}
 public function testRegistrationAssociatesErrorsAndFocusesFirstInvalidField():void { $errors=['name'=>'Informe seu nome.','email'=>'E-mail inválido.','password'=>'Senha inválida.'];$old=['name'=>'','email'=>'bad'];$message=null;ob_start();require dirname(__DIR__,2).'/app/Views/auth/register.php';$html=(string)ob_get_clean();self::assertStringContainsString('id="name-error"',$html);self::assertStringContainsString('aria-describedby="name-error"',$html);self::assertStringContainsString('aria-invalid="true"',$html);self::assertMatchesRegularExpression('/<input[^>]+id="name"[^>]+autofocus/',$html);self::assertStringContainsString('id="password-error"',$html);self::assertStringContainsString('aria-describedby="password-error"',$html);}
 public function testMarketingLayoutHasNoRuntimeCdnDependency():void { $content='<main>ok</main>';$title='Teste';ob_start();require dirname(__DIR__,2).'/app/Views/layouts/marketing.php';$html=(string)ob_get_clean();self::assertStringNotContainsString('cdn.jsdelivr.net',$html);self::assertStringContainsString('/assets/css/app.css',$html); }
}
