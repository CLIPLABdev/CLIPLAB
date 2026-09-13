<?php

declare(strict_types=1);

namespace App\Media;

final class YoutubeFailureClassifier
{
    public static function fromStderr(string $stderr): string
    {
        $text = strtolower(substr($stderr, 0, 65536));
        $text = preg_replace('#https?://\S+#', '', $text) ?? '';
        if (preg_match('/\b(?:http(?: error)?|status(?: code)?)\s*:?\s*429\b/', $text)) {
            return 'youtube_rate_limited';
        }
        $patterns = [
            'youtube_bot_challenge' => ['not a bot'],
            'youtube_age_restricted' => ['confirm your age', 'age-restricted', 'age restricted'],
            'youtube_region_restricted' => ['not available in your country', 'not available in your region'],
            'youtube_private_video' => ['private video'],
            'youtube_rate_limited' => ['too many requests'],
            'youtube_network_failed' => ['timed out', 'connection refused', 'network is unreachable', 'temporary failure in name resolution'],
            'youtube_format_unavailable' => ['requested format is not available'],
            'youtube_login_required' => ['login required', 'sign in'],
        ];
        foreach ($patterns as $code => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $code;
                }
            }
        }
        return 'youtube_video_unavailable';
    }
}
