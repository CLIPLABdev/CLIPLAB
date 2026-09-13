<?php

declare(strict_types=1);

namespace App\Ai;

use InvalidArgumentException;

final class AiAnalysisReceipt
{
    private const ALLOWED_STATUSES = [
        'queued',
        'uploading',
        'waiting_file',
        'generating',
        'validating',
        'completed',
        'failed',
    ];

    private int $analysisId;
    private ?int $reservationId;
    private string $status;
    private bool $created;

    public function __construct(int $analysisId, ?int $reservationId, string $status, bool $created)
    {
        if ($analysisId <= 0) {
            throw new InvalidArgumentException('Analysis id must be positive.');
        }
        if ($reservationId !== null && $reservationId <= 0) {
            throw new InvalidArgumentException('Reservation id must be positive when present.');
        }
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Analysis receipt status is invalid.');
        }

        $this->analysisId = $analysisId;
        $this->reservationId = $reservationId;
        $this->status = $status;
        $this->created = $created;
    }

    public function analysisId(): int
    {
        return $this->analysisId;
    }

    public function reservationId(): ?int
    {
        return $this->reservationId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function created(): bool
    {
        return $this->created;
    }
}
