<?php

declare(strict_types=1);

namespace App\Media;

final class DownloadRequest
{
    public function __construct(
        private ValidatedRemoteUrl $url,
        private int $timeoutSeconds,
        private int $maxBytes,
        private ?int $rangeStart = null,
        private ?int $rangeEnd = null
    ) {
        if ($timeoutSeconds < 1 || $maxBytes < 1) {
            throw new \InvalidArgumentException('Download limits must be positive.');
        }
        if (($rangeStart === null) !== ($rangeEnd === null)
            || ($rangeStart !== null && ($rangeStart < 0 || $rangeEnd < $rangeStart || $rangeEnd - $rangeStart >= $maxBytes))) {
            throw new \InvalidArgumentException('Invalid bounded byte range.');
        }
    }

    public function url(): ValidatedRemoteUrl { return $this->url; }
    public function timeoutSeconds(): int { return $this->timeoutSeconds; }
    public function maxBytes(): int { return $this->maxBytes; }
    public function rangeStart(): ?int { return $this->rangeStart; }
    public function rangeEnd(): ?int { return $this->rangeEnd; }
}
