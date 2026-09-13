<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\ErrorHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$environmentFile = getenv('APP_ENV_FILE');
if ($environmentFile === false) {
    Env::load(dirname(__DIR__) . '/.env');
} elseif ($environmentFile !== '') {
    Env::load($environmentFile);
}

$debug = filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL);
$logger = new \App\Core\Logger(null, static function (array $event): void {
    (new \App\Repositories\SystemLogRepository(\App\Core\Database::connection()))
        ->tryRecord($event['level'], $event['event'], $event['context']);
});
(new ErrorHandler($logger))->register($debug === true);
