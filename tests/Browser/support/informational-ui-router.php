<?php

declare(strict_types=1);

// Dedicated presentation fixture. Never bootstraps environment, database or external services.
$root=dirname(__DIR__,3);
$path=parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'),PHP_URL_PATH);
if ($path==='/__information_ready') { echo 'information-ui-fixture'; return; }
if (in_array($path,['/assets/css/app.css','/assets/css/informational.css'],true)) {
    $asset=$root.'/public'.$path;
    if (is_file($asset)) {
        header('Content-Type: text/css; charset=UTF-8');
        readfile($asset);
        return;
    }
    http_response_code(404);
    return;
}
require $root.'/vendor/autoload.php';
$router=new \App\Core\Router();
require $root.'/routes/informational.php';
$router->dispatch(\App\Core\Request::capture())->send();
