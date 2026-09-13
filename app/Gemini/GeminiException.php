<?php

declare(strict_types=1);

namespace App\Gemini;

use InvalidArgumentException;
use RuntimeException;

final class GeminiException extends RuntimeException
{
    private const MESSAGES = [
        'ai_unconfigured' => 'A análise por IA não está configurada.',
        'ai_timeout' => 'A análise por IA excedeu o tempo limite.',
        'ai_rate_limited' => 'O serviço de IA está temporariamente sobrecarregado.',
        'ai_unavailable' => 'O serviço de IA está temporariamente indisponível.',
        'ai_provider_rejected' => 'O serviço de IA rejeitou a solicitação.',
    ];

    private const TRANSIENT = [
        'ai_timeout',
        'ai_rate_limited',
        'ai_unavailable',
    ];

    private string $publicCode;
    /** @var array<string,int|string> */
    private array $diagnostic = [];

    private function __construct(string $publicCode)
    {
        if (!isset(self::MESSAGES[$publicCode])) {
            throw new InvalidArgumentException('Gemini error code is invalid.');
        }

        parent::__construct(self::MESSAGES[$publicCode]);
        $this->publicCode = $publicCode;
    }

    public static function withCode(string $publicCode, array $diagnostic = []): self
    {
        $exception = new self($publicCode);
        foreach (['http_status'=>[100,599], 'curl_errno'=>[0,999], 'retry_after_seconds'=>[1,900]] as $key=>$range) {
            if (is_int($diagnostic[$key] ?? null) && $diagnostic[$key] >= $range[0] && $diagnostic[$key] <= $range[1]) {
                $exception->diagnostic[$key] = $diagnostic[$key];
            }
        }
        if (in_array($diagnostic['failure_kind'] ?? null, ['http','transport','internal'], true)) {
            $exception->diagnostic = ['failure_kind'=>$diagnostic['failure_kind']] + $exception->diagnostic;
        }
        if (!$exception->isTransient()) unset($exception->diagnostic['retry_after_seconds']);
        return $exception;
    }

    public static function fromHttpResponse(GeminiHttpResponse $response, ?int $now = null): self
    {
        $status = $response->status();
        $code = $status === 408 ? 'ai_timeout' : ($status === 429 ? 'ai_rate_limited' : ($status >= 500 ? 'ai_unavailable' : 'ai_provider_rejected'));
        $diagnostic = ['failure_kind'=>'http','http_status'=>$status];
        $hint = $response->header('retry-after');
        if (is_string($hint) && strlen($hint) <= 128) {
            if (preg_match('/\A[0-9]+\z/D', $hint)) {
                $digits = ltrim($hint, '0');
                $seconds = strlen($digits) > 3 ? 900 : min(900, (int)$digits);
            } else {
                $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $hint, new \DateTimeZone('UTC'));
                $seconds = $date !== false && $date->format('D, d M Y H:i:s \G\M\T') === $hint
                    ? min(900, $date->getTimestamp() - ($now ?? time())) : 0;
            }
            if ($seconds > 0) $diagnostic['retry_after_seconds'] = $seconds;
        }
        return self::withCode($code, $diagnostic);
    }

    /** No URLs, provider bodies, headers, exception messages or keys are retained. @return array<string,int|string> */
    public function safeDiagnostic(): array { return $this->diagnostic; }

    public function retryAfterSeconds(): ?int { return $this->diagnostic['retry_after_seconds'] ?? null; }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function isTransient(): bool
    {
        return in_array($this->publicCode, self::TRANSIENT, true);
    }
}
