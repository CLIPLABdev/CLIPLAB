<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class ProjectReceipt
{
    private int $projectId;
    private string $status;
    private bool $created;

    public function __construct(int $projectId, string $status, bool $created)
    {
        if ($projectId <= 0) {
            throw new InvalidArgumentException('Project id must be positive.');
        }
        if (trim($status) === '') {
            throw new InvalidArgumentException('Project status must not be empty.');
        }
        $this->projectId = $projectId;
        $this->status = $status;
        $this->created = $created;
    }

    public function projectId(): int { return $this->projectId; }
    public function status(): string { return $this->status; }
    public function created(): bool { return $this->created; }
}
