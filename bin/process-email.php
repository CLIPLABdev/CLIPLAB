#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Communications\EmailOutboxWorker;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Security\SecretCipher;
use App\Services\MailerFactory;

require dirname(__DIR__) . '/bootstrap/app.php';

$limit = 1;
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--limit=') || !ctype_digit(substr($argument, 8))) {
        fwrite(STDERR, "Uso: php bin/process-email.php [--limit=1..10]\n");
        exit(2);
    }
    $limit = (int) substr($argument, 8);
}
if ($limit < 1 || $limit > 10) {
    fwrite(STDERR, "Uso: php bin/process-email.php [--limit=1..10]\n");
    exit(2);
}

try {
    $pdo = Database::connection();
    $cipher = new SecretCipher((string) Env::get('APP_ENCRYPTION_KEY', ''));
    $settings = new \App\Communications\CommunicationMailSettingsService($pdo, $cipher, (array) Config::get('mail', []));
    $configuration = $settings->effective();
    if (($configuration['transport'] ?? '') !== 'smtp') throw new RuntimeException('SMTP is not configured.');
    $mailer = MailerFactory::make($configuration);
    (new \App\Communications\CommunicationTemplateService($pdo))->installDefaults();
    $worker = new EmailOutboxWorker(
        $pdo,
        $cipher,
        $mailer
    );
    $result = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'cancelled' => 0, 'idle' => 0];
    for ($index = 0; $index < $limit; ++$index) {
        $status = $worker->processOne();
        ++$result[$status];
        if ($status === 'idle') {
            break;
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Falha ao processar e-mails.\n");
    exit(1);
}
