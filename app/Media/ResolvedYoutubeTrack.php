<?php

declare(strict_types=1);

namespace App\Media;

final class ResolvedYoutubeTrack
{
    public function __construct(
        private ValidatedRemoteUrl $url,
        private string $kind,
        private string $extension,
        private string $mimeType
    ) {
        if (!in_array($kind, ['video', 'audio'], true)
            || !in_array($extension, ['mp4', 'm4a'], true)
            || ($kind === 'video' && $extension !== 'mp4')
            || ($kind === 'audio' && $extension !== 'm4a')) {
            throw new \InvalidArgumentException('Invalid resolved YouTube track.');
        }
    }

    public function url(): ValidatedRemoteUrl { return $this->url; }
    public function kind(): string { return $this->kind; }
    public function extension(): string { return $this->extension; }
    public function mimeType(): string { return $this->mimeType; }
}
