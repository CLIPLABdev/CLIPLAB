<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\InformationalController;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class InformationalPagesTest extends TestCase
{
    protected function setUp(): void { $_SESSION=[]; }
    protected function tearDown(): void { $_SESSION=[]; }

    public function testGuestCanReadBothPublicPagesWithoutAccountOrDatabase(): void
    {
        $router=new Router();
        require dirname(__DIR__,2).'/routes/informational.php';
        foreach (['/privacidade'=>'Privacidade','/termos'=>'Termos de uso'] as $path=>$heading) {
            $response=$router->dispatch(Request::fake('GET',$path));
            self::assertSame(200,$response->status());
            self::assertSame('text/html; charset=UTF-8',$response->header('Content-Type'));
            self::assertNull($response->header('Location'));
            $xpath=$this->document($response->body());
            self::assertSame($heading,trim($xpath->query('//main//h1')->item(0)->textContent));
            self::assertSame(1,$xpath->query('//main')->length);
            self::assertSame(1,$xpath->query('//html[@lang="pt-BR"]')->length);
            self::assertSame(0,$xpath->query('//form | //video | //iframe')->length);
            self::assertSame(1,$xpath->query('//header//a[@href="/privacidade"]')->length);
            self::assertSame(1,$xpath->query('//footer//a[@href="/termos"]')->length);
            self::assertSame(1,$xpath->query('//header//a[@aria-current="page"]')->length);
        }
    }

    public function testRouteRegistrationIsLazyAndDoesNotAcceptMutations(): void
    {
        $router=new Router();
        $calls=0;
        $informationalControllerFactory=static function () use (&$calls): InformationalController {
            $calls++;
            return new InformationalController(new View());
        };
        require dirname(__DIR__,2).'/routes/informational.php';
        self::assertSame(0,$calls);
        self::assertSame(405,$router->dispatch(Request::fake('POST','/privacidade'))->status());
        self::assertSame(0,$calls);
        self::assertSame(200,$router->dispatch(Request::fake('GET','/termos'))->status());
        self::assertSame(1,$calls);
    }

    public function testQueryAndSessionDataCannotAppearInInformationalResponses(): void
    {
        $_SESSION=['user_id'=>71,'email'=>'private-canary@example.invalid','api_key'=>'secret-canary-abc','flash'=>'<script>alert(1)</script>'];
        $before=$_SESSION;
        $router=new Router();
        require dirname(__DIR__,2).'/routes/informational.php';
        foreach (['/privacidade','/termos'] as $path) {
            $response=$router->dispatch(Request::fake('GET',$path.'?'.http_build_query(['title'=>'<img src=x onerror=alert(2)>','private_path'=>'private-canary/video.mp4'])));
            foreach (['private-canary','secret-canary','alert(1)','alert(2)','api_key'] as $secret) self::assertStringNotContainsString($secret,$response->body());
            self::assertSame(0,$this->document($response->body())->query('//script | //*[@onclick or @onerror]')->length);
        }
        self::assertSame($before,$_SESSION);
    }

    public function testContentTemplateEscapesTextAndAnchorAttributes(): void
    {
        $response=(new View())->render('informational.page',[
            'page'=>'privacy','title'=>'<img src=x onerror=alert(1)>','introduction'=>'<script>secret</script>',
            'sections'=>[['id'=>'detail" onclick="alert(1)','title'=>'<svg onload=alert(1)>',
                'paragraphs'=>['<iframe src="https://example.invalid">','<b>not markup</b>']]],
        ]);
        $xpath=$this->document($response->body());
        self::assertSame('<img src=x onerror=alert(1)>',trim($xpath->query('//main//h1')->item(0)->textContent));
        self::assertSame(0,$xpath->query('//script | //img | //iframe | //*[@onclick or @onerror or @onload]')->length);
        self::assertStringContainsString('&lt;b&gt;not markup&lt;/b&gt;',$response->body());
        self::assertSame('#detail" onclick="alert(1)',$xpath->query('//nav[@aria-label="Nesta página"]//a')->item(0)->getAttribute('href'));
    }

    public function testSectionNavigationHasRealUniqueTargetsAndExternalLinksAreExplicit(): void
    {
        $controller=new InformationalController(new View());
        foreach ([$controller->privacy(),$controller->terms()] as $response) {
            $xpath=$this->document($response->body());
            $ids=[];
            foreach ($xpath->query('//*[@id]') as $node) {
                self::assertNotContains($node->getAttribute('id'),$ids);
                $ids[]=$node->getAttribute('id');
            }
            foreach ($xpath->query('//a[starts-with(@href,"#")]') as $link) self::assertContains(substr($link->getAttribute('href'),1),$ids);
            foreach ($xpath->query('//a[starts-with(@href,"https://")]') as $link) {
                self::assertStringContainsString('noreferrer',$link->getAttribute('rel'));
                self::assertNotEmpty(trim($link->textContent));
            }
        }
    }

    private function document(string $body): \DOMXPath
    {
        $document=new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$body);
        return new \DOMXPath($document);
    }
}
