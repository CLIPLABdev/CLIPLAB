<?php

declare(strict_types=1);

namespace App\Media;

use App\Exceptions\MediaValidationException;

final class YoutubeUrlValidator
{
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'];

    public function recognizes(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts)
            && is_string($parts['host'] ?? null)
            && in_array(strtolower($parts['host']), self::HOSTS, true);
    }

    public function validate(string $url): ValidatedYoutubeUrl
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || !in_array(strtolower($parts['host']), self::HOSTS, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])) {
            throw MediaValidationException::withCode('unsupported_youtube_url');
        }

        $host = strtolower($parts['host']);
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (isset($query['list'])) {
            throw MediaValidationException::withCode('unsupported_youtube_url');
        }

        $videoId = null;
        if ($host === 'youtu.be') {
            if (preg_match('#^/([A-Za-z0-9_-]{11})/?$#D', $path, $match) === 1) {
                $videoId = $match[1];
            }
        } elseif ($path === '/watch' && is_string($query['v'] ?? null)) {
            $videoId = $query['v'];
        } elseif (preg_match('#^/(?:shorts|embed)/([A-Za-z0-9_-]{11})/?$#D', $path, $match) === 1) {
            $videoId = $match[1];
        }

        if (!is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId) !== 1) {
            throw MediaValidationException::withCode('unsupported_youtube_url');
        }

        return new ValidatedYoutubeUrl(
            'https://www.youtube.com/watch?v=' . $videoId,
            'www.youtube.com',
            $videoId
        );
    }
}
