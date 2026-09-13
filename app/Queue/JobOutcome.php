<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;

final class JobOutcome
{
    private function __construct(
        private string $status,
        private ?string $code = null,
        private ?string $publicMessage = null,
        private ?int $delaySeconds = null
    )
    {
        if (!in_array($status, ['completed', 'retry', 'deferred', 'failed'], true)) {
            throw new InvalidArgumentException('Job outcome status is invalid.');
        }
        if ($status === 'completed' && ($code !== null || $publicMessage !== null || $delaySeconds !== null)) {
            throw new InvalidArgumentException('Completed jobs cannot have an error or delay.');
        }
        if ($status === 'deferred') {
            if ($code !== null || $publicMessage !== null || $delaySeconds === null || $delaySeconds < 5 || $delaySeconds > 300) {
                throw new InvalidArgumentException('Deferred jobs require only a delay between 5 and 300 seconds.');
            }

            return;
        }
        if ($status !== 'completed' && (trim((string) $code) === '' || trim((string) $publicMessage) === '' || strlen((string) $publicMessage) > 255)) {
            throw new InvalidArgumentException('Failed and retry outcomes require a bounded public error.');
        }
        if ($delaySeconds !== null && ($status !== 'retry' || $delaySeconds < 1 || $delaySeconds > 900)) {
            throw new InvalidArgumentException('Retry delay must be between 1 and 900 seconds.');
        }
    }

    public static function completed(): self { return new self('completed'); }
    public static function retry(string $code, string $publicMessage, ?int $delaySeconds = null): self { return new self('retry', $code, $publicMessage, $delaySeconds); }
    public static function deferred(int $delaySeconds): self { return new self('deferred', null, null, $delaySeconds); }
    public static function failed(string $code, string $publicMessage): self { return new self('failed', $code, $publicMessage); }
    public function status(): string { return $this->status; }
    public function code(): ?string { return $this->code; }
    public function publicMessage(): ?string { return $this->publicMessage; }
    public function delaySeconds(): ?int { return $this->delaySeconds; }
}
