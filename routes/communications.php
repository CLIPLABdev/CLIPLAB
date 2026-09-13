<?php
declare(strict_types=1);
use App\Communications\{CommunicationInboxService,CommunicationMailSettingsService,CommunicationEmitterService,CommunicationEventCatalog,EmailVerificationService};
use App\Controllers\{CommunicationAdminController,CommunicationController,EmailVerificationController};
use App\Core\{Config,Env,Router,View};
use App\Security\SecretCipher;
use App\Services\RateLimiter;

return static function(Router $router,array $deps):void {
    $auth=$deps['authenticated'];$admin=$deps['admin_only'];$view=$deps['view']??new View();
    $resolvedPdo=null;$resolvedCipher=null;$controllers=[];
    $pdo=static function()use($deps,&$resolvedPdo):\PDO {
        if($resolvedPdo===null){$value=$deps['pdo']??static fn()=>\App\Core\Database::connection();$resolvedPdo=is_callable($value)?$value():$value;}return $resolvedPdo;
    };
    $cipher=static function()use($deps,&$resolvedCipher):SecretCipher {
        if($resolvedCipher===null){$value=$deps['cipher']??static fn()=>new SecretCipher((string)Env::get('APP_ENCRYPTION_KEY',''));$resolvedCipher=is_callable($value)?$value():$value;}return $resolvedCipher;
    };
    // Resolve dependencies only after the route's authorization middleware runs.
    $controller=static function(string $name)use($deps,$view,$pdo,$cipher,&$controllers):object {
        if(isset($controllers[$name]))return $controllers[$name];
        $connection=$pdo();$limiter=new RateLimiter($connection);
        if($name==='user')return $controllers[$name]=new CommunicationController($view,new CommunicationInboxService($connection),new \App\Repositories\UserRepository($connection));
        if($name==='admin')return $controllers[$name]=new CommunicationAdminController($view,$connection,new CommunicationMailSettingsService($connection,$cipher(),$deps['mail_config']??(array)Config::get('mail',[])),$limiter,$deps['mailer_factory']??null);
        $value=$deps['communications']??null;$emitter=is_callable($value)?$value():$value;
        $emitter??=new CommunicationEmitterService($connection,$cipher(),new CommunicationEventCatalog());
        return $controllers[$name]=new EmailVerificationController($view,new EmailVerificationService($connection,$emitter,$deps['base_url']??(string)Config::get('app.url','')),$limiter);
    };
    $router->get('/notificacoes',static fn($r)=>$controller('user')->notifications($r),[$auth]);
    $router->post('/notificacoes/{id}/ler',static fn($r,$p)=>$controller('user')->read($r,$p),[$auth]);
    $router->get('/preferencias',static fn($r)=>$controller('user')->preferences($r),[$auth]);
    $router->post('/preferencias',static fn($r)=>$controller('user')->savePreferences($r),[$auth]);
    $router->get('/admin/emails',static fn($r)=>$controller('admin')->templates($r),[$admin]);
    $router->post('/admin/emails',static fn($r)=>$controller('admin')->saveTemplate($r),[$admin]);
    $router->post('/admin/emails/{id}/duplicar',static fn($r,$p)=>$controller('admin')->duplicate($r,$p),[$admin]);
    $router->post('/admin/emails/{id}/ativar',static fn($r,$p)=>$controller('admin')->activate($r,$p),[$admin]);
    $router->get('/admin/emails/{id}/preview',static fn($r,$p)=>$controller('admin')->preview($r,$p),[$admin]);
    $router->get('/admin/email-configuracao',static fn($r)=>$controller('admin')->settings($r),[$admin]);
    $router->post('/admin/email-configuracao',static fn($r)=>$controller('admin')->saveSettings($r),[$admin]);
    $router->post('/admin/email-configuracao/testar',static fn($r)=>$controller('admin')->testSend($r),[$admin]);
    $router->get('/verificar-email',static fn($r)=>$controller('verification')->form($r));
    $router->post('/verificar-email',static fn($r)=>$controller('verification')->confirm($r));
    $router->post('/verificar-email/reenviar',static fn($r)=>$controller('verification')->resend($r),[$auth]);
};
