<?php

declare(strict_types=1);

use App\Controllers\InformationalController;
use App\Core\View;

// Optional branding context falls back gracefully when the database is unavailable.
$informationalView=$sharedView ?? new View();
$informationalControllerFactory=$informationalControllerFactory ?? static fn (): InformationalController => new InformationalController($informationalView);
$router->get('/privacidade',static fn () => $informationalControllerFactory()->privacy());
$router->get('/termos',static fn () => $informationalControllerFactory()->terms());
