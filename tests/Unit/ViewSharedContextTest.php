<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ViewSharedContextTest extends TestCase
{
    public function testSharedContextIsInjectedWithoutOverridingTrustedViewData(): void
    {
        $_SESSION=[];
        $calls=[];
        $view=new View(null,static function(string $name,array $data) use (&$calls):array {
            $calls[]=$name;
            return ['title'=>'Injected wrong title','platformBranding'=>['name'=>'Meu Estúdio','description'=>'','logo_url'=>null,'favicon_url'=>null]];
        });
        $html=$view->render('profile.confirm-email',['title'=>'Confirmar e-mail','token'=>'','user'=>['id'=>1,'name'=>'Ana','email'=>'a@example.test','plan_name'=>'Free','credits'=>0,'monthly_minutes'=>10]])->body();
        self::assertSame(['profile.confirm-email'],$calls);
        self::assertStringNotContainsString('Injected wrong title',$html);
        self::assertStringContainsString('Meu Estúdio',$html);
    }
}
