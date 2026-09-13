<?php
declare(strict_types=1);
use App\Core\Router;
return static function(Router $router,array $deps):void {
    $billing=require __DIR__.'/billing.php';
    $billing($router,['pdo_factory'=>$deps['pdo'],'cipher_factory'=>$deps['cipher'],'view'=>$deps['view'],
        'authenticated'=>$deps['authenticated'],'admin_only'=>$deps['admin_only'],'return_base'=>$deps['base_url'],
        'communication_emitter'=>$deps['communications'],
        'user_profile'=>static fn(int $id)=>(new \App\Repositories\UserRepository(($deps['pdo'])()))->findDashboardProfile($id)]);
    $communications=require __DIR__.'/communications.php';$communications($router,$deps);
    $campaigns=require __DIR__.'/campaigns.php';$campaigns($router,$deps);
};
