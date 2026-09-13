<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'api_key' => trim((string) Env::get('GEMINI_API_KEY', '')),
    'model' => trim((string) Env::get('GEMINI_MODEL', '')),
    'base_url' => 'https://generativelanguage.googleapis.com',
    'http_timeout_seconds' => max(10, min(300, (int) Env::get('GEMINI_HTTP_TIMEOUT_SECONDS', '180'))),
    'response_limit_bytes' => max(16384, min(4194304, (int) Env::get('GEMINI_RESPONSE_LIMIT_BYTES', '1048576'))),
    'file_poll_seconds' => max(5, min(300, (int) Env::get('GEMINI_FILE_POLL_SECONDS', '15'))),
    'validation_attempts' => 2,
    'analysis_max_attempts' => max(1, min(8, (int) (Env::get('GEMINI_ANALYSIS_MAX_ATTEMPTS', '6') ?: '6'))),
    'credits_per_minute' => max(1, min(100, (int) Env::get('GEMINI_CREDITS_PER_MINUTE', '1'))),
];
