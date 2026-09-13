<?php

declare(strict_types=1);

namespace App\Contracts;

interface SmtpSocket
{
    public function connect(string $host, int $port, int $timeout, bool $ssl): void;
    public function readLine(): string;
    public function write(string $data): void;
    public function enableCrypto(): void;
    public function close(): void;
}
