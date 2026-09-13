#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Services\LocalRuntimeStatus;

try {
    require dirname(__DIR__) . '/bootstrap/app.php';
    if (array_slice($argv, 1) !== []) {
        throw new InvalidArgumentException('Local status does not accept arguments.');
    }
    $status = (new LocalRuntimeStatus(Database::connection()))->collect(
        (array) Config::get('media', []),
        (array) Config::get('gemini', []),
        'media'
    );
    echo json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "{\"ok\":false,\"error\":\"local_status_unavailable\"}" . PHP_EOL);
    exit(1);
}
