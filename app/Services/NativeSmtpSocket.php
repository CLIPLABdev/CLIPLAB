<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SmtpSocket;
use RuntimeException;

final class NativeSmtpSocket implements SmtpSocket
{
    /** @var resource|null */
    private $stream;
    private float $deadline = 0.0;

    public function connect(string $host, int $port, int $timeout, bool $ssl): void
    {
        $timeout = max(1, min(60, $timeout));
        $this->deadline = microtime(true) + $timeout;
        $target = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $stream = @stream_socket_client($target, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT);
        if ($stream === false) {
            throw new RuntimeException('Unable to connect to SMTP server.');
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
    }

    public function readLine(): string
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('SMTP connection is not open.');
        }
        $this->applyRemainingTimeout();
        $line = fgets($this->stream, 8192);
        if ($line === false) {
            throw new RuntimeException('SMTP server did not respond.');
        }
        return $line;
    }

    public function write(string $data): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('SMTP connection is not open.');
        }
        $remaining = $data;
        while ($remaining !== '') {
            $this->applyRemainingTimeout();
            $written = fwrite($this->stream, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write to SMTP server.');
            }
            $remaining = (string) substr($remaining, $written);
        }
    }

    public function enableCrypto(): void
    {
        $this->applyRemainingTimeout();
        if (!is_resource($this->stream) || stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new RuntimeException('Unable to secure SMTP connection.');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /** Bound the entire conversation, so a slow server cannot outlive its outbox lease. */
    private function applyRemainingTimeout(): void
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0 || !is_resource($this->stream)) throw new RuntimeException('SMTP operation timed out.');
        $seconds = (int) floor($remaining);
        stream_set_timeout($this->stream, $seconds, max(1, (int) (($remaining - $seconds) * 1000000)));
    }
}
