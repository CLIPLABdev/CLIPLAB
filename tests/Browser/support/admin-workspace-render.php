<?php
declare(strict_types=1);
// Synthetic view data only. This fixture never boots the application or opens a database.
require dirname(__DIR__, 3) . '/vendor/autoload.php';
$_SESSION = ['_csrf'=>str_repeat('a',64)];
if (($argv[1] ?? '') === 'users') {
    $_SERVER['REQUEST_URI'] = '/admin/usuarios';
    echo (new App\Core\View())->render('admin.users', [
        'title'=>'Usuários','admin'=>['name'=>'Marina Silva','email'=>'marina@example.test'],'flash'=>null,
        'platformFeatures'=>['admin_tools'=>true,'billing'=>true,'communications'=>true,'campaigns'=>true],
        'plans'=>[['id'=>1,'name'=>'Creator']],
        'page'=>['items'=>[['id'=>17,'name'=>'Ana Martins','email'=>'ana@example.test','role'=>'user','status'=>'active','plan_id'=>1,'plan_name'=>'Creator','credits'=>120,'created_at'=>'2026-09-01 09:00:00']],
            'filters'=>['q'=>'','status'=>'all','plan'=>''],'page'=>1,'last_page'=>1,'total'=>1],
    ])->body();
    exit;
}
$_SERVER['REQUEST_URI'] = '/admin';
echo (new App\Core\View())->render('admin.dashboard', [
    'title'=>'Administração','admin'=>['name'=>'Marina Silva','email'=>'marina@example.test'],'flash'=>null,
    'platformFeatures'=>['admin_tools'=>true,'billing'=>true,'communications'=>true,'campaigns'=>true],
    'metrics'=>['users_total'=>128,'users_active'=>120,'users_new'=>18,'users_suspended'=>8,'projects_total'=>342,
        'projects_processing'=>4,'jobs_queued'=>12,'jobs_running'=>4,'jobs_failed'=>3,'credits_balance'=>4260,
        'videos_processed'=>286,'clips_completed'=>914,'plans_in_use'=>3,'storage_bytes'=>18253611008,
        'recent_activity'=>[['created_at'=>'2026-09-07 14:32:00','public_message'=>'Plano da conta atualizado.'],['created_at'=>'2026-09-07 14:20:00','public_message'=>'Créditos adicionados à conta.']]],
    'financial'=>['net_total_cents'=>1892000,'net_month_cents'=>284900,'subscriptions_active'=>42,'subscriptions_total'=>51,
        'subscriptions_canceled'=>9,'payments_paid'=>136,'payments_pending'=>3,'payments_failed'=>2],
])->body();
