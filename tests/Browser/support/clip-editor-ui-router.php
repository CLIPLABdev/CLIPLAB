<?php

declare(strict_types=1);

// Isolated browser presentation fixture: no application bootstrap, database or provider.
$root=dirname(__DIR__,3);
$path=parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'),PHP_URL_PATH);
if ($path==='/__editor_ready') { echo 'editor-ui-fixture'; return; }
// Destination for the browser's intercepted save response; no persistence/provider bootstrap.
if ($path==='/templates' && ($_SERVER['REQUEST_METHOD'] ?? '')==='GET') { header('Content-Type: text/html; charset=UTF-8'); echo '<p>Biblioteca de teste isolada.</p>'; return; }
if (is_string($path) && preg_match('#^/assets/[a-zA-Z0-9_./-]+\.(?:js|css)$#D',$path)===1) {
    $asset=realpath($root.'/public'.$path);
    $public=realpath($root.'/public/assets');
    if (is_string($asset) && str_starts_with(str_replace('\\','/',$asset),str_replace('\\','/',$public).'/')) {
        header('Content-Type: '.(str_ends_with($path,'.css') ? 'text/css' : 'text/javascript'));
        readfile($asset);
        return;
    }
}
require $root.'/vendor/autoload.php';
\App\Core\Session::put('user_id',7);
$controller=new \App\Controllers\ClipEditorController(new \App\Core\View(),
    static function (int $id,int $user): ?array {
        if ($user!==7 || !in_array($id,[41,42,43],true)) return null;
        return array_replace(\Tests\Support\ClipEditorFixture::clip(),['id'=>$id,'status'=>$id===42 ? 'queued' : 'completed']);
    },
    static function (int $id): array {
        $snapshot=\Tests\Support\ClipEditorFixture::snapshot();
        if ($id===43) {
            $snapshot['transcript']=\App\Media\Subtitles\TranscriptValidator::fromArray(['language'=>'pt-BR','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Olá, mundo!','words'=>[
                ['start_ms'=>0,'end_ms'=>500,'text'=>'Olá,'],['start_ms'=>500,'end_ms'=>1000,'text'=>'mundo!']
            ]]]],10000);
            $snapshot['options']=\App\Media\Editor\EditorOptions::fromArray(['style'=>'karaoke']);
        }
        return $snapshot;
    },
    static function ($id,$user,$key,$start,$end,$options,$reframe,$mode,$srt): \App\Media\ClipRenderReceipt {
        try {
            $duration=(int)round(((float)$end-(float)$start)*1000);
            if ($duration<1000 || $duration>180000) throw new \InvalidArgumentException();
            (new \App\Media\Reframe\ReframePlanValidator())->validate($reframe,$duration);
            if ($mode==='manual') \App\Media\Subtitles\SrtCodec::parse($srt,$duration);
        } catch (\InvalidArgumentException) {
            throw new \App\Exceptions\ClipRenderValidationException(['subtitles'=>'Revise os tempos do SRT e o intervalo.'],12);
        }
        return new \App\Media\ClipRenderReceipt(42,12,1,true);
    }, null,null,static fn (): bool => true);
$router=new \App\Core\Router();
$editorControllerFactory=static fn () => $controller;
$authenticated=new class { public function handle($request,callable $next) { return $next($request); } };
require $root.'/routes/editor.php';
$router->dispatch(\App\Core\Request::capture())->send();
