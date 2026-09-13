<?php

declare(strict_types=1);

use App\Core\Env;

$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::get('DB_PORT', '3306');
$database = Env::get('DB_DATABASE', 'clipforge');
$charset = Env::get('DB_CHARSET', 'utf8mb4');

return [
    'dsn' => Env::get('DB_DSN', sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset)),
    'username' => Env::get('DB_USERNAME', ''),
    'password' => Env::get('DB_PASSWORD', ''),
];
