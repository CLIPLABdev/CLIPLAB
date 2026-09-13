<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private ?\Closure $operationalSink;
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'authorization',
        'api_key',
        'gemini_api_key',
        'mail_smtp_password',
    ];

    public function __construct(private ?string $path = null, ?callable $operationalSink = null)
    {
        $this->path ??= dirname(__DIR__, 2) . '/storage/logs/app.log';
        $this->operationalSink = $operationalSink === null ? null : \Closure::fromCallable($operationalSink);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $correlationId = isset($context['correlation_id']) && is_string($context['correlation_id'])
            ? $context['correlation_id']
            : bin2hex(random_bytes(16));

        $entry = [
            'timestamp' => gmdate('c'),
            'level' => 'error',
            'correlation_id' => $correlationId,
            'message' => $message,
            'context' => $this->sanitize($context),
        ];

        $directory = dirname((string) $this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded !== false) {
            @file_put_contents((string) $this->path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        if ($this->operationalSink !== null) {
            try {
                ($this->operationalSink)([
                    'level' => 'error',
                    'event' => 'system.operation_failed',
                    'context' => [
                        'source' => 'application',
                        'correlation_id' => preg_match('/^[a-f0-9]{32}$/D', $correlationId) === 1 ? $correlationId : bin2hex(random_bytes(16)),
                    ],
                ]);
            } catch (\Throwable) {
                // Database logging must never hide the original error or disable the file log.
            }
        }
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function sanitize(array $context): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)
                ? '[REDACTED]'
                : (is_array($value) ? $this->sanitize($value) : $value);
        }

        return $context;
    }
}
