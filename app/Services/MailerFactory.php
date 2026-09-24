<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Mailer;
use RuntimeException;

final class MailerFactory
{
    /** @param array<string, mixed> $config */
    public static function make(array $config): Mailer
    {
        $transport = strtolower(trim((string) ($config['transport'] ?? '')));
        if ($transport === 'log') {
            $environment = strtolower(trim((string) ($config['environment'] ?? 'production')));
            if (!in_array($environment, ['local', 'testing', 'test'], true)) {
                throw new RuntimeException('Development mail logging is unavailable in this environment.');
            }
            return new LogMailer((string) ($config['log_file'] ?? ''));
        }
        if ($transport === 'mail') {
            return new NativeMailer((string) ($config['from_address'] ?? ''), (string) ($config['from_name'] ?? 'ClipLab'));
        }
        if ($transport !== 'smtp') {
            throw new RuntimeException('Unsupported mail transport.');
        }

        $host = trim((string) ($config['smtp_host'] ?? ''));
        $port = (int) ($config['smtp_port'] ?? 0);
        $encryption = strtolower(trim((string) ($config['smtp_encryption'] ?? '')));
        $username = (string) ($config['smtp_username'] ?? '');
        $password = (string) ($config['smtp_password'] ?? '');
        $fromAddress = (string) ($config['from_address'] ?? '');
        $timeout = (int) ($config['smtp_timeout'] ?? 10);
        if ($host === '' || $port < 1 || $port > 65535 || !in_array($encryption, ['tls', 'ssl'], true) || $timeout < 1 || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || (($username === '') !== ($password === ''))) {
            throw new RuntimeException('Invalid SMTP configuration.');
        }

        return new SmtpMailer($host, $port, $encryption, $username, $password, $fromAddress, (string) ($config['from_name'] ?? 'ClipLab'), $timeout);
    }
}
