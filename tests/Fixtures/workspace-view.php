<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
\App\Core\Session::start();$_SESSION=['_csrf'=>str_repeat('a',64),'user_id'=>17];
$title='Clipes';$user=['id'=>17,'name'=>'Ana Ribeiro','email'=>'ana@example.test','credits'=>42,'plan_name'=>'Creator','monthly_minutes'=>120];
$platformFeatures=['billing'=>true,'communications'=>true];$notificationUnread=3;
$platformBranding=['name'=>'ClipForge','description'=>'','logo_url'=>null,'favicon_url'=>null];
$content='<section class="panel"><h2>Biblioteca de cortes</h2><p>Escolha o próximo vídeo para editar.</p><form id="qa-form" method="post" action="/perfil" data-platform-form><label for="qa-name">Nome</label><input id="qa-name" name="name" value="Ana" required><button class="button" type="submit" name="intent" value="save">Salvar</button></form></section>';
ob_start();require dirname(__DIR__,2).'/app/Views/layouts/app.php';$html=(string)ob_get_clean();
$response=(new \App\Middleware\SecurityHeadersMiddleware())->handle(\App\Core\Request::fake('GET','/clips'),static fn()=>\App\Core\Response::html($html));
echo json_encode(['html'=>$response->body(),'csp'=>$response->header('Content-Security-Policy')],JSON_THROW_ON_ERROR);
