<?php

declare(strict_types=1);

namespace App\Ai;

use InvalidArgumentException;
use RuntimeException;

final class InvalidAnalysisResponse extends RuntimeException
{
    private const PUBLIC_MESSAGE = 'A resposta da análise de IA é inválida.';

    private const ALLOWED_REASON_CODES = [
        'response_too_large',
        'invalid_json',
        'invalid_shape',
        'invalid_fields',
        'invalid_clip_count',
        'invalid_text',
        'invalid_timestamp',
        'invalid_duration',
        'invalid_timeline',
        'invalid_score',
        'invalid_category',
        'duplicate_clip',
        'invalid_domain',
    ];

    private string $reasonCode;

    public function __construct(string $reasonCode)
    {
        if (!in_array($reasonCode, self::ALLOWED_REASON_CODES, true)) {
            throw new InvalidArgumentException('Invalid AI analysis response reason code.');
        }

        parent::__construct(self::PUBLIC_MESSAGE);
        $this->reasonCode = $reasonCode;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
