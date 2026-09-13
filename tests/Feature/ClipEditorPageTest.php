<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ClipEditorController;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Media\ClipRenderReceipt;
use PHPUnit\Framework\TestCase;
use Tests\Support\ClipEditorFixture as Fixture;

final class ClipEditorPageTest extends TestCase
{
    protected function setUp(): void { $_SESSION=[]; Session::put('user_id',7); }
    protected function tearDown(): void { $_SESSION=[]; }

    private function controller(?array $clip=null, ?callable $request=null, ?callable $snapshot=null): ClipEditorController
    {
        return new ClipEditorController(new View(),static fn () => $clip ?? Fixture::clip(),$snapshot ?? static fn () => Fixture::snapshot(),
            $request ?? static fn () => new ClipRenderReceipt(42,12,1,true),null,null,static fn (): bool => true);
    }

    public function testPageEscapesContentOffersAllControlsAndLoadsNoMediaBeforeGesture(): void
    {
        $clip=array_replace(Fixture::clip(),['title'=>'<img src=x onerror=alert(1)>','object_key'=>'secret/private.mp4']);
        $response=$this->controller($clip)->show(Request::fake('GET','/'),['id'=>'41']);
        self::assertSame(200,$response->status());
        self::assertSame('private, no-store',$response->header('Cache-Control'));
        $document=new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->body());
        $xpath=new \DOMXPath($document);
        self::assertSame(1,$xpath->query('//form[@method="post" and @action="/clips/41/editar"]')->length);
        self::assertSame(6,$xpath->query('//select[@name="style"]/option')->length);
        self::assertSame(0,$xpath->query('//video[@src or @autoplay] | //video/source')->length);
        self::assertSame(1,$xpath->query('//video[@preload="none"]')->length);
        foreach (['start_time','end_time','aspect_ratio','reframe_mode','focus_x','focus_y','style','position','color','accent_color','font_size','title','watermark','srt','transcript_mode'] as $name) {
            $nodes=$xpath->query('//*[@name="'.$name.'"]');
            self::assertSame(1,$nodes->length,$name);
            $id=$nodes->item(0)->getAttribute('id');
            self::assertSame(1,$xpath->query('//label[@for="'.$id.'"]')->length,$name.' label');
        }
        self::assertSame(1,$xpath->query('//a[@href="/clips/41/legendas.srt"]')->length);
        self::assertSame(1,$xpath->query('//*[@data-consent-active="0"]')->length);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;',$response->body());
        self::assertStringNotContainsString('secret/private.mp4',$response->body());
        self::assertStringContainsString('Olá, mundo!',$response->body());
        self::assertStringContainsString('Novas exportações em retrato',$response->body());
        self::assertStringContainsString('guias são genéricas',$response->body());
        self::assertStringContainsString('Versões já concluídas permanecem preservadas',$response->body());
        self::assertSame(0,$xpath->query('//select[@name="style"]/option[@value="none"]')->length);
        self::assertSame(0,$xpath->query('//select[@name="transcript_mode"]/option[@value="none"]')->length);
    }

    public function testNoAudioDefaultsToManualCaptions(): void
    {
        $response=$this->controller(array_replace(Fixture::clip(),['has_audio'=>0]),null,static fn (): ?array => null)->show(Request::fake('GET','/'),['id'=>'41']);
        self::assertStringContainsString('option value="manual" selected',$response->body());
        self::assertStringContainsString('SRT revisado não vazio',$response->body());
    }

    public function testPendingPageReportsRealStatusAndOffersRefreshWithoutAnotherExport(): void
    {
        $response=$this->controller(array_replace(Fixture::clip(),['status'=>'queued']))->show(Request::fake('GET','/'),['id'=>'41']);
        self::assertStringContainsString('Na fila',$response->body());
        self::assertStringContainsString('href="/clips/41/editar"',$response->body());
        self::assertStringNotContainsString('data-editor-submit',$response->body());
    }

    public function testFailedCaptionShowsSanitizedRecoveryMessage(): void
    {
        $captionFailure=$this->controller(array_replace(Fixture::clip(),['status'=>'failed','render_error_code'=>'subtitle_empty']))->show(Request::fake('GET','/'),['id'=>'41']);
        self::assertStringContainsString('Não foi possível encontrar fala para gerar legendas neste corte.',$captionFailure->body());
        self::assertStringContainsString('Revise o áudio ou use um SRT revisado',$captionFailure->body());

        $unknownFailure=$this->controller(array_replace(Fixture::clip(),['status'=>'failed','render_error_code'=>'private-error-code']))->show(Request::fake('GET','/'),['id'=>'41']);
        self::assertStringContainsString('Não foi possível concluir esta versão.',$unknownFailure->body());
        self::assertStringNotContainsString('private-error-code',$unknownFailure->body());
    }

    public function testRoutesAreLazyAndPostUsesGlobalCsrfBeforeExport(): void
    {
        $router=new Router();
        $calls=0;
        $controller=$this->controller();
        $editorControllerFactory=static function () use (&$calls,$controller): ClipEditorController { $calls++; return $controller; };
        $authenticated=new class { public function handle(Request $request,callable $next): Response { return $next($request); } };
        require dirname(__DIR__,2).'/routes/editor.php';
        self::assertSame(0,$calls);
        $response=$router->dispatch(Request::fake('POST','/clips/41/editar',Fixture::input()));
        self::assertSame(419,$response->status());
        self::assertSame(0,$calls);
        self::assertSame(200,$router->dispatch(Request::fake('GET','/clips/41/editar'))->status());
        self::assertSame(200,$router->dispatch(Request::fake('GET','/clips/41/legendas.srt'))->status());
        $response=$router->dispatch(Request::fake('POST','/clips/41/editar',Fixture::input()+['_token'=>Csrf::token()]));
        self::assertSame('/clips/42/editar',$response->header('Location'));
    }

    public function testDefaultRoutesRedirectGuestWithoutConnectingDatabase(): void
    {
        $_SESSION=[];
        $router=new Router();
        require dirname(__DIR__,2).'/routes/editor.php';
        self::assertSame('/login',$router->dispatch(Request::fake('GET','/clips/41/editar'))->header('Location'));
        self::assertSame('/login',$router->dispatch(Request::fake('GET','/clips/41/legendas.srt'))->header('Location'));
    }
}
