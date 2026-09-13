#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\SystemLogRepository;
use App\Services\AdminBootstrapService;

require dirname(__DIR__) . '/bootstrap/app.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando só pode ser executado no terminal.\n");
    exit(1);
}

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--password')) {
        fwrite(STDERR, "A senha nunca pode ser informada por argumento.\n");
        exit(2);
    }
}

$options = getopt('', ['email:', 'name:', 'promote']);
$email = is_string($options['email'] ?? null) ? $options['email'] : '';
$name = is_string($options['name'] ?? null) ? $options['name'] : '';
$promote = array_key_exists('promote', $options);
$password = null;

if ($email === '' || (!$promote && $name === '')) {
    fwrite(STDERR, "Uso: php bin/create-admin.php --email=voce@dominio --name=Nome\n");
    fwrite(STDERR, "Ou:  php bin/create-admin.php --email=conta@dominio --promote\n");
    exit(2);
}

if (!$promote) {
    $interactive = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($interactive && (DIRECTORY_SEPARATOR === '\\' || !function_exists('system'))) {
        fwrite(STDERR, "Neste sistema, envie a senha pela entrada padrão protegida para evitar eco no terminal.\n");
        exit(2);
    }

    fwrite(STDERR, "Senha do novo administrador (mínimo 12 caracteres): ");
    $hidden = $interactive && DIRECTORY_SEPARATOR !== '\\';
    if ($hidden) {
        @system('stty -echo');
    }
    try {
        $line = fgets(STDIN);
    } finally {
        if ($hidden) {
            @system('stty echo');
            fwrite(STDERR, "\n");
        }
    }
    if (!is_string($line)) {
        fwrite(STDERR, "Não foi possível ler a senha pela entrada padrão.\n");
        exit(2);
    }
    $password = rtrim($line, "\r\n");
}

try {
    $pdo = Database::connection();
    $service = new AdminBootstrapService($pdo, new SystemLogRepository($pdo));
    $userId = $service->provision($email, $name, $password, $promote);
    fwrite(STDOUT, "Administrador provisionado com sucesso (ID {$userId}).\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Não foi possível provisionar o administrador. Verifique os dados e as migrations.\n");
    exit(1);
}
