<?php

declare(strict_types=1);

use App\Core\Env;
use App\Media\PrivateMediaRoot;
use App\Media\UploadLimits;

$configuredMaxUploadBytes = max(1, (int) Env::get('MEDIA_MAX_UPLOAD_BYTES', '524288000'));
$uploadLimits = UploadLimits::runtime($configuredMaxUploadBytes);

return [
    'disk' => Env::get('MEDIA_DISK', 'local'),
    'private_root' => PrivateMediaRoot::resolve(
        Env::get('MEDIA_PRIVATE_ROOT'),
        dirname(__DIR__),
        dirname(__DIR__) . '/public'
    ),
    'configured_max_upload_bytes' => $uploadLimits->configuredBytes(),
    'max_upload_bytes' => $uploadLimits->configuredBytes(),
    'effective_upload_bytes' => $uploadLimits->effectiveBytes(),
    'php_upload_max_bytes' => $uploadLimits->phpUploadMaxBytes(),
    'php_post_max_bytes' => $uploadLimits->phpPostMaxBytes(),
    'php_upload_capacity_bytes' => $uploadLimits->phpCapacityBytes(),
    'allowed_extensions' => ['mp4', 'mov', 'webm'],
    'download_timeout_seconds' => max(5, (int) Env::get('MEDIA_DOWNLOAD_TIMEOUT_SECONDS', '120')),
    'max_redirects' => max(0, (int) Env::get('MEDIA_MAX_REDIRECTS', '2')),
    'youtube_import_enabled' => filter_var(Env::get('YOUTUBE_IMPORT_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'yt_dlp_binary' => Env::get('YTDLP_BINARY', 'yt-dlp'),
    'yt_dlp_js_runtime' => Env::get('YTDLP_JS_RUNTIME'),
    'yt_dlp_cookies_file' => Env::get('YTDLP_COOKIES_FILE'),
    'yt_dlp_remote_components' => Env::get('YTDLP_REMOTE_COMPONENTS'),
    'yt_dlp_force_ipv4' => filter_var(Env::get('YTDLP_FORCE_IPV4', 'true'), FILTER_VALIDATE_BOOL),
    'yt_dlp_timeout_seconds' => max(5, (int) Env::get('YTDLP_TIMEOUT_SECONDS', '60')),
    'yt_dlp_output_limit_bytes' => max(4096, (int) Env::get('YTDLP_OUTPUT_LIMIT_BYTES', '1048576')),
    'ffprobe_binary' => Env::get('FFPROBE_BINARY', 'ffprobe'),
    'ffmpeg_binary' => Env::get('FFMPEG_BINARY', 'ffmpeg'),
    'process_timeout_seconds' => max(5, (int) Env::get('PROCESS_TIMEOUT_SECONDS', '60')),
    'process_output_limit_bytes' => max(4096, (int) Env::get('PROCESS_OUTPUT_LIMIT_BYTES', '1048576')),
    'render_timeout_seconds' => max(5, (int) Env::get('RENDER_TIMEOUT_SECONDS', '240')),
    'render_max_output_bytes' => max(1, (int) Env::get('RENDER_MAX_OUTPUT_BYTES', '524288000')),
    'render_max_duration_seconds' => max(1, min(180, (int) Env::get('RENDER_MAX_DURATION_SECONDS', '180'))),
    'render_thumbnail_max_bytes' => max(1, (int) Env::get('RENDER_THUMBNAIL_MAX_BYTES', '10485760')),
    'reframe_max_duration_seconds' => max(1, min(180, (int) Env::get('REFRAME_MAX_DURATION_SECONDS', '90'))),
    'reframe_max_keyframes' => (int) Env::get('REFRAME_MAX_KEYFRAMES', '32'),
    'reframe_preview_max_frames' => max(2, min(180, (int) Env::get('REFRAME_PREVIEW_MAX_FRAMES', '180'))),
    'reframe_preview_max_edge' => max(64, min(320, (int) Env::get('REFRAME_PREVIEW_MAX_EDGE', '320'))),
    'mediapipe_asset_version' => Env::get('MEDIAPIPE_ASSET_VERSION', '1.0.1'),
    'queue' => [
        'lease_seconds' => max(60, (int) Env::get('QUEUE_LEASE_SECONDS', '300')),
        'max_attempts' => max(1, (int) Env::get('QUEUE_MAX_ATTEMPTS', '3')),
        'batch_size' => max(1, min(10, (int) Env::get('QUEUE_BATCH_SIZE', '1'))),
    ],
];
