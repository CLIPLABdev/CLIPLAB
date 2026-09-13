<?php

declare(strict_types=1);

namespace App\Media;

final class ValidatedRemoteUrl
{
    /** @param list<string> $addresses */
    public function __construct(private string $url, private string $host, private array $addresses)
    {
    }

    public function url(): string { return $this->url; }
    public function host(): string { return $this->host; }
    /** @return list<string> */
    public function addresses(): array { return $this->addresses; }
}
