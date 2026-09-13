#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migrator;

require dirname(__DIR__) . '/bootstrap/app.php';

$pdo = Database::connection();
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
$executed = $migrator->run();

$seed = dirname(__DIR__) . '/database/seeds/plans.sql';

if (is_file($seed)) {
    $pdo->exec((string) file_get_contents($seed));
}

if ($executed === []) {
    echo "Nenhuma migration pendente\n";
    exit(0);
}

foreach ($executed as $migration) {
    echo "Aplicada: {$migration}\n";
}
