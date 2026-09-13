<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Mailer;
use RuntimeException;

final class LogMailer implements Mailer
{
    public function __construct(private string $logFile)
    {
    }

    public function send(string $recipient, string $subject, string $html): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid mail recipient.');
        }

        $directory = dirname($this->logFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create mail log directory.');
        }

        $entry = sprintf(
            "[%s] development-mail recipient=%s status=recorded_without_body\n",
            gmdate('c'),
            str_replace(["\r", "\n"], '', $recipient)
        );

        if (file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to write mail log.');
        }
    }
}
