<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Mailer;
use RuntimeException;

final class NativeMailer implements Mailer
{
    public function __construct(private string $fromAddress, private string $fromName = 'ClipForge')
    {
    }

    public function send(string $recipient, string $subject, string $html): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid mail address configuration.');
        }

        foreach ([$recipient, $subject, $this->fromAddress, $this->fromName] as $value) {
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new RuntimeException('Invalid mail header.');
            }
        }

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromName . ' <' . $this->fromAddress . '>',
        ]);

        if (!mail($recipient, $subject, $html, $headers)) {
            throw new RuntimeException('Unable to send mail.');
        }
    }
}
