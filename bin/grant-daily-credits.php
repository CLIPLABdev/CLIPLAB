#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Concede os créditos diários dos planos. Seguro para rodar várias vezes por dia:
 * cada conta recebe no máximo uma vez por dia (fuso APP_TIMEZONE, padrão America/Sao_Paulo).
 */

use App\Core\Database;
use App\Core\Env;
use App\Services\DailyPlanCreditService;

require dirname(__DIR__) . '/bootstrap/app.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando só pode ser executado no terminal.\n");
    exit(1);
}

try {
    $service = new DailyPlanCreditService(
        Database::connection(),
        new DateTimeZone((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'))
    );
    $total = ['granted' => 0, 'skipped' => 0, 'credits' => 0];
    for ($batch = 0; $batch < 50; $batch++) {
        $result = $service->grantDue();
        foreach ($total as $key => $value) {
            $total[$key] = $value + $result[$key];
        }
        if ($result['granted'] + $result['skipped'] === 0) {
            break;
        }
    }
    echo json_encode($total, JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, "Falha ao conceder créditos diários: " . get_class($exception) . "\n");
    exit(1);
}
