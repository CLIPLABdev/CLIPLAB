<?php

declare(strict_types=1);

use Tests\Support\SafePhase5TestDatabase;
use Tests\Support\SmartReframeGateMutex;

$projectRoot = dirname(__DIR__, 3);
require $projectRoot . '/vendor/autoload.php';

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 1) {
        throw new RuntimeException('invalid_invocation');
    }
    $dsn = SafePhase5TestDatabase::validatedDsn(getenv('DB_DSN'));
    $username = getenv('DB_USERNAME');
    $password = getenv('DB_PASSWORD');
    if (!is_string($username) || trim($username) === '' || !is_string($password)) {
        throw new RuntimeException('invalid_database');
    }
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'cliplab_phase5_test') {
        throw new RuntimeException('invalid_database');
    }

    $mutex = SmartReframeGateMutex::acquire($pdo, 60);
    echo json_encode(['locked' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
    flush();
    fgets(STDIN);
    $mutex->release();
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Smart reframe test gate failed.\n");
    exit(1);
}
