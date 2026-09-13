<?php

declare(strict_types=1);

namespace App\Media;

final class ResolvedYoutubeMedia
{
    /** @param list<ResolvedYoutubeTrack> $tracks */
    public function __construct(private ?ValidatedRemoteUrl $url = null, private array $tracks = [])
    {
        if (($url === null) === ($tracks === [])) {
            throw new \InvalidArgumentException('Resolved YouTube media must be progressive or adaptive.');
        }
        if ($tracks !== [] && (count($tracks) !== 2
            || $tracks[0]->kind() !== 'video'
            || $tracks[1]->kind() !== 'audio')) {
            throw new \InvalidArgumentException('Adaptive YouTube media requires one video and one audio track.');
        }
    }

    public static function adaptive(ResolvedYoutubeTrack $video, ResolvedYoutubeTrack $audio): self
    {
        return new self(null, [$video, $audio]);
    }

    public function isAdaptive(): bool { return $this->tracks !== []; }
    public function url(): ValidatedRemoteUrl
    {
        if ($this->url === null) {
            throw new \LogicException('Adaptive YouTube media has no progressive URL.');
        }
        return $this->url;
    }
    /** @return list<ResolvedYoutubeTrack> */
    public function tracks(): array { return $this->tracks; }
    public function extension(): string { return 'mp4'; }
    public function mimeType(): string { return 'video/mp4'; }
}
