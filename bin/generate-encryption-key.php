#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ApplicationEncryptionKeyProvisioner;

require dirname(__DIR__) . '/bootstrap/app.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando só pode ser executado no terminal.\n");
    exit(1);
}
if (count($argv) !== 1) {
    fwrite(STDERR, "Uso: php bin/generate-encryption-key.php\n");
    exit(2);
}

try {
    $status = (new ApplicationEncryptionKeyProvisioner(Database::connection(), dirname(__DIR__)))
        ->provision(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    if ($status === 'already_configured') {
        fwrite(STDOUT, "APP_ENCRYPTION_KEY já está configurada e não foi alterada.\n");
    } else {
        fwrite(STDOUT, "APP_ENCRYPTION_KEY criada no .env sem exibir o valor. Reinicie a web e o worker.\n");
    }
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Não foi possível gerar APP_ENCRYPTION_KEY com segurança. Verifique o banco, as migrations e o .env privado; nenhum valor foi exibido.\n");
    exit(1);
}
