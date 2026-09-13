<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $asset = is_string($path) ? __DIR__ . $path : null;

    if ($asset !== null && $path !== '/' && is_file($asset)) {
        return false;
    }
}

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Middleware\SecurityHeadersMiddleware;

ini_set('display_errors', '0');

try {
    $bootstrapCorrelationId = bin2hex(random_bytes(16));
} catch (\Throwable) {
    $bootstrapCorrelationId = uniqid('bootstrap-', true);
}

$bootstrapResponseSent = false;
$bootstrapComplete = false;
$renderBootstrapFailure = static function () use (&$bootstrapResponseSent, $bootstrapCorrelationId): void {
    if ($bootstrapResponseSent) {
        return;
    }

    $bootstrapResponseSent = true;
    error_log('ClipForge bootstrap failure [' . $bootstrapCorrelationId . ']');

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Erro temporário</title></head><body><main><h1>Erro temporário. Tente novamente.</h1><p>Código de referência: ' . htmlspecialchars($bootstrapCorrelationId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></main></body></html>';
};

register_shutdown_function(static function () use (&$bootstrapComplete, $renderBootstrapFailure): void {
    if ($bootstrapComplete) {
        return;
    }

    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $renderBootstrapFailure();
    }
});

try {
    @require dirname(__DIR__) . '/bootstrap/app.php';
    $bootstrapComplete = true;
} catch (\Throwable) {
    $renderBootstrapFailure();
    exit(1);
}

Session::start();
$router = new Router();
$routes = dirname(__DIR__) . '/routes/web.php';

if (is_file($routes)) {
    $configuredRouter = require $routes;

    if ($configuredRouter instanceof Router) {
        $router = $configuredRouter;
    }
}

$request = Request::capture();
$response = (new SecurityHeadersMiddleware())->handle(
    $request,
    fn (Request $currentRequest) => $router->dispatch($currentRequest)
);
$response->send();
