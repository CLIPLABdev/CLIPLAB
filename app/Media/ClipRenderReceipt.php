<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class ClipRenderReceipt
{
    public function __construct(
        private int $clipId,
        private int $projectId,
        private int $revision,
        private bool $created
    ) {
        if ($clipId < 1 || $projectId < 1 || $revision < 1) {
            throw new InvalidArgumentException('Clip render receipt identifiers must be positive.');
        }
    }

    public function clipId(): int
    {
        return $this->clipId;
    }

    public function projectId(): int
    {
        return $this->projectId;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function created(): bool
    {
        return $this->created;
    }
}
