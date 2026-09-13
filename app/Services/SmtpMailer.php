<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Mailer;
use App\Contracts\SmtpSocket;
use RuntimeException;
use Throwable;

final class SmtpMailer implements Mailer
{
    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private string $fromAddress,
        private string $fromName = 'ClipForge',
        private int $timeout = 10,
        private ?SmtpSocket $socket = null
    ) {
    }

    public function send(string $recipient, string $subject, string $html): void
    {
        $this->validateHeader($recipient);
        $this->validateHeader($subject);
        $this->validateHeader($this->fromAddress);
        $this->validateHeader($this->fromName);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid mail address configuration.');
        }

        $socket = $this->socket ?? new NativeSmtpSocket();
        try {
            $socket->connect($this->host, $this->port, $this->timeout, $this->encryption === 'ssl');
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . $this->clientName(), [250]);
            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $socket->enableCrypto();
                $this->command($socket, 'EHLO ' . $this->clientName(), [250]);
            }
            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->username), [334]);
                $this->command($socket, base64_encode($this->password), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $this->fromAddress . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $socket->write($this->message($recipient, $subject, $html) . "\r\n.\r\n");
            $this->expect($socket, [250]);
            // DATA 250 is authoritative acceptance. QUIT cannot undo it.
            try { $this->command($socket, 'QUIT', [221]); } catch (Throwable) {}
        } catch (Throwable $exception) {
            throw new RuntimeException('SMTP delivery failed.', 0, $exception);
        } finally {
            $socket->close();
        }
    }

    /** @param array<int, int> $codes */
    private function command(SmtpSocket $socket, string $command, array $codes): void
    {
        $socket->write($command . "\r\n");
        $this->expect($socket, $codes);
    }

    /** @param array<int, int> $codes */
    private function expect(SmtpSocket $socket, array $codes): void
    {
        $lines = 0;
        do {
            if (++$lines > 100) throw new RuntimeException('SMTP response is too long.');
            $line = $socket->readLine();
            if (!preg_match('/^(\d{3})([ -])/', $line, $match)) {
                throw new RuntimeException('Invalid SMTP response.');
            }
            $code = (int) $match[1];
            $continued = $match[2] === '-';
        } while ($continued);

        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP command was rejected.');
        }
    }

    private function message(string $recipient, string $subject, string $html): string
    {
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $html) ?? '';
        $body = quoted_printable_encode($body);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        return implode("\r\n", [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $this->fromName . ' <' . $this->fromAddress . '>',
            'To: <' . $recipient . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            $body,
        ]);
    }

    private function validateHeader(string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('Invalid mail header.');
        }
    }

    private function clientName(): string
    {
        $name = gethostname();
        return is_string($name) && $name !== '' ? $name : 'localhost';
    }
}
