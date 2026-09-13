<?php

declare(strict_types=1);

namespace App\Media;

final class ValidatedYoutubeUrl
{
    public function __construct(private string $url, private string $host, private string $videoId)
    {
    }

    public function url(): string { return $this->url; }
    public function host(): string { return $this->host; }
    public function videoId(): string { return $this->videoId; }
}
