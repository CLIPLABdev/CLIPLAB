<?php

declare(strict_types=1);

use App\Core\Env;

$environment = Env::get('APP_ENV', 'production');
$defaultLogFile = dirname(__DIR__) . '/storage/logs/password-reset.log';

return [
    'environment' => $environment,
    'transport' => Env::get('MAIL_TRANSPORT', $environment === 'local' ? 'log' : 'smtp'),
    'from_address' => Env::get('MAIL_FROM_ADDRESS', ''),
    'from_name' => Env::get('MAIL_FROM_NAME', 'ClipForge'),
    // Password-reset links are recoverable only from this protected, development-only log.
    'log_file' => Env::get('MAIL_LOG_FILE', $defaultLogFile),
    'smtp_host' => Env::get('MAIL_SMTP_HOST', ''),
    'smtp_port' => (int) Env::get('MAIL_SMTP_PORT', '587'),
    'smtp_encryption' => Env::get('MAIL_SMTP_ENCRYPTION', 'tls'),
    'smtp_username' => Env::get('MAIL_SMTP_USERNAME', ''),
    'smtp_password' => Env::get('MAIL_SMTP_PASSWORD', ''),
    'smtp_timeout' => (int) Env::get('MAIL_SMTP_TIMEOUT', '10'),
];
