<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';
$_SESSION = ['user_id' => 8];
echo (new \App\Core\View())->render('projects.create', [
    'title' => 'Novo projeto',
    'user' => ['id' => 8, 'name' => 'Teste', 'email' => 'test@example.test', 'credits' => 10, 'plan_name' => 'Free', 'monthly_minutes' => 60],
    'old' => [], 'errors' => getenv('TEST_FORM_ERROR') === '1' ? ['form' => 'Não foi possível validar esse arquivo de vídeo.'] : [],
    'idempotencyKey' => 'browser-fixture', 'maxUploadBytes' => 1024,
])->body();
