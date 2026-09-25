<?php

declare(strict_types=1);

/*
 * Diagnóstico da importação do YouTube no servidor.
 * Uso: php tools/diagnose-youtube.php "https://www.youtube.com/watch?v=..."
 * Reproduz o mesmo caminho do worker (yt-dlp + download PHP) e mostra o erro real.
 */

use App\Core\Config;
use App\Media\CurlDownloadTransport;
use App\Media\DirectUrlValidator;
use App\Media\DownloadRequest;
use App\Media\PinnedHttpDownloader;
use App\Media\UploadValidator;
use App\Media\YoutubeUrlValidator;
use App\Media\YtDlpYoutubeResolver;
use App\Process\ProcessRunner;
use App\Storage\LocalPrivateStorage;

require dirname(__DIR__) . '/bootstrap/app.php';

$link = $argv[1] ?? '';
if ($link === '') {
    fwrite(STDERR, "Uso: php tools/diagnose-youtube.php \"https://www.youtube.com/watch?v=...\"\n");
    exit(2);
}

$media = (array) Config::get('media', []);
$privateRoot = (string) $media['private_root'];
@mkdir($privateRoot, 0775, true);
$maxBytes = (int) ($media['effective_upload_bytes'] ?? 524288000);
$binary = (string) ($media['yt_dlp_binary'] ?? 'yt-dlp');
$ffmpeg = (string) ($media['ffmpeg_binary'] ?? 'ffmpeg');

echo "private_root: {$privateRoot}\nlimite: {$maxBytes} bytes\ncookies: " . var_export($media['yt_dlp_cookies_file'] ?? null, true)
    . "\njs_runtime: " . var_export($media['yt_dlp_js_runtime'] ?? null, true) . "\n\n";

$failure = static function (Throwable $e): string {
    $code = method_exists($e, 'publicCode') ? $e->publicCode() : '';
    return get_class($e) . ($code !== '' ? " [{$code}]" : '') . ': ' . $e->getMessage();
};

// 1) Resolução pelo yt-dlp
try {
    $resolver = new YtDlpYoutubeResolver(
        new ProcessRunner([$binary], $privateRoot),
        new DirectUrlValidator(),
        $binary,
        (int) ($media['yt_dlp_timeout_seconds'] ?? 60),
        (int) ($media['yt_dlp_output_limit_bytes'] ?? 1048576),
        is_string($media['yt_dlp_js_runtime'] ?? null) ? $media['yt_dlp_js_runtime'] : null,
        (bool) ($media['yt_dlp_force_ipv4'] ?? true),
        null,
        is_string($media['yt_dlp_cookies_file'] ?? null) ? $media['yt_dlp_cookies_file'] : null
    );
    $resolved = $resolver->resolveWithinLimit((new YoutubeUrlValidator())->validate($link), $maxBytes);
} catch (Throwable $e) {
    echo "1) yt-dlp FALHOU: " . $failure($e) . "\n";
    exit(1);
}
$tracks = $resolved->isAdaptive() ? $resolved->tracks() : [];
$urls = $resolved->isAdaptive() ? array_map(static fn ($t) => $t->url(), $tracks) : [$resolved->url()];
echo "1) yt-dlp OK — formato " . ($resolved->isAdaptive() ? 'adaptativo (vídeo + áudio separados)' : 'progressivo (arquivo único)') . "\n";

// 2) Primeiro 1 MB de cada trilha com as MESMAS opções de cURL do sistema
foreach ($urls as $i => $url) {
    $transport = new CurlDownloadTransport();
    $bytes = 0;
    $options = $transport->optionsFor(
        new DownloadRequest($url, 30, 1048576, 0, 1048575),
        static function ($h, string $chunk) use (&$bytes): int { $bytes += strlen($chunk); return strlen($chunk); },
        static fn ($h, string $line): int => strlen($line)
    );
    $handle = curl_init();
    curl_setopt_array($handle, $options);
    curl_exec($handle);
    printf("2.%d) cURL do sistema: HTTP %d, %d bytes, IP %s, erro cURL %d %s\n", $i + 1,
        curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $bytes, curl_getinfo($handle, CURLINFO_PRIMARY_IP),
        curl_errno($handle), curl_error($handle));
    curl_close($handle);
}

// 3) Download completo pelo mesmo caminho do worker
$storage = new LocalPrivateStorage($privateRoot, $maxBytes);
$downloader = new PinnedHttpDownloader(
    new DirectUrlValidator(), new UploadValidator($maxBytes),
    (int) ($media['download_timeout_seconds'] ?? 120), (int) ($media['max_redirects'] ?? 2),
    $storage, null, new ProcessRunner([$ffmpeg], $privateRoot), $ffmpeg,
    (int) ($media['process_timeout_seconds'] ?? 60), (int) ($media['process_output_limit_bytes'] ?? 1048576)
);
$key = 'imports/diagnostico/' . bin2hex(random_bytes(8)) . '.mp4';
$started = microtime(true);
try {
    $object = $downloader->downloadYoutube($resolved, $storage, $key, $maxBytes);
    printf("3) Download completo OK: %.1f MB em %.0f s\n", $object->sizeBytes() / 1048576, microtime(true) - $started);
    $storage->delete($object->objectKey());
} catch (Throwable $e) {
    printf("3) Download completo FALHOU após %.0f s: %s\n", microtime(true) - $started, $failure($e));
    $previous = $e->getPrevious();
    while ($previous !== null) {
        echo "   causa: " . $failure($previous) . "\n";
        $previous = $previous->getPrevious();
    }
}
