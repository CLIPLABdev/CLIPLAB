<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\AuthMiddleware;

return static function (Router $router, AccountController $account, AuthMiddleware $authenticated): void {
    $router->get('/conta/plano', static fn () => $account->plan(), [$authenticated]);
    $router->get('/conta/creditos', static fn (Request $request) => $account->credits($request), [$authenticated]);
};
