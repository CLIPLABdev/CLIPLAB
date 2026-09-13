<?php
declare(strict_types=1);
use App\Communications\{CampaignService,CampaignUnsubscribeService};
use App\Controllers\CampaignController;
use App\Core\{Database,Env,Request,Router,View};
use App\Security\SecretCipher;

return static function(Router $router,array $deps):void {
    $admin=$deps['admin_only']??null;
    if($admin===null)throw new \InvalidArgumentException('Campaigns require administrator middleware.');
    $factory=static function()use($deps):CampaignController {
        static $controller=null;if($controller!==null)return $controller;
        $connection=$deps['pdo']??static fn()=>Database::connection();$pdo=is_callable($connection)?$connection():$connection;
        $secret=$deps['cipher']??static fn()=>new SecretCipher((string)Env::get('APP_ENCRYPTION_KEY',''));$cipher=is_callable($secret)?$secret():$secret;
        $clock=$deps['clock']??null;
        $unsubscribe=new CampaignUnsubscribeService($pdo,$cipher,(string)($deps['base_url']??Env::get('APP_URL','')),$clock);
        return $controller=new CampaignController($deps['view']??new View(),new CampaignService($pdo,$cipher,$unsubscribe,$clock),$unsubscribe,$pdo);
    };
    $router->get('/admin/campanhas',static fn(Request $r)=>$factory()->index($r),[$admin]);
    $router->get('/admin/campanhas/nova',static fn(Request $r)=>$factory()->form($r),[$admin]);
    $router->post('/admin/campanhas',static fn(Request $r)=>$factory()->create($r),[$admin]);
    $router->get('/admin/campanhas/{id}/editar',static fn(Request $r,array $p)=>$factory()->form($r,$p),[$admin]);
    $router->post('/admin/campanhas/{id}',static fn(Request $r,array $p)=>$factory()->update($r,$p),[$admin]);
    $router->post('/admin/campanhas/{id}/agendar',static fn(Request $r,array $p)=>$factory()->schedule($r,$p),[$admin]);
    $router->post('/admin/campanhas/{id}/cancelar',static fn(Request $r,array $p)=>$factory()->cancel($r,$p),[$admin]);
    $router->get('/admin/campanhas/{id}/historico',static fn(Request $r,array $p)=>$factory()->history($r,$p),[$admin]);
    $router->get('/cancelar-inscricao',static fn(Request $r)=>$factory()->unsubscribeForm($r));
    $router->post('/cancelar-inscricao',static fn(Request $r)=>$factory()->unsubscribe($r));
};
