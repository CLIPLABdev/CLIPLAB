<?php

declare(strict_types=1);

namespace App\Media;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;

/**
 * Baixa o vídeo do YouTube com o próprio yt-dlp.
 *
 * Os endereços do googlevideo ficam presos ao cliente que o yt-dlp usou para
 * obtê-los (cabeçalhos, User-Agent e desafio "n"). Baixá-los depois com outro
 * cliente HTTP gera 403 de forma intermitente em servidores. Aqui a obtenção e
 * o download acontecem na mesma execução do yt-dlp, que aplica tudo isso sozinho.
 */
final class YtDlpMediaFetcher
{
    private const DEFAULT_SELECTOR = 'best[ext=mp4][vcodec!=none][acodec!=none]/bestvideo[ext=mp4][vcodec^=avc1]+bestaudio[ext=m4a]';

    /** @param null|callable(array<string, mixed>): void $report */
    public function __construct(
        private ProcessRunner $runner,
        private UploadValidator $validator,
        private string $workRoot,
        private string $binary = 'yt-dlp',
        private int $timeoutSeconds = 900,
        private ?string $javascriptRuntime = null,
        private bool $forceIpv4 = true,
        private ?string $cookiesFile = null,
        private ?string $remoteComponents = null,
        private ?string $cacheDirectory = null,
        private ?string $ffmpegBinary = null,
        private $report = null
    ) {
        if (trim($binary) === '' || trim($workRoot) === '' || $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('YouTube fetcher configuration is invalid.');
        }
    }

    public function supports(ResolvedYoutubeMedia $media): bool
    {
        return $media->sourceUrl() !== null;
    }

    public function fetch(ResolvedYoutubeMedia $media, PrivateStorage $storage, string $objectKey, int $maxBytes): StoredObject
    {
        $source = $media->sourceUrl();
        if ($source === null) {
            throw MediaValidationException::withCode('youtube_import_unavailable');
        }
        if ($maxBytes < 1) {
            throw MediaValidationException::withCode('media_too_large');
        }

        $directory = rtrim($this->workRoot, '/\\') . DIRECTORY_SEPARATOR . '.ytdlp-' . bin2hex(random_bytes(12));
        if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        $started = microtime(true);
        $exit = null;
        try {
            try {
                $result = $this->runner->run($this->command($media, $source, $directory, $maxBytes), $this->timeoutSeconds, 1048576);
            } catch (ProcessExecutionException $exception) {
                throw MediaValidationException::withCode(match ($exception->publicCode()) {
                    'process_timeout' => 'remote_timeout',
                    'process_unavailable' => 'youtube_import_unavailable',
                    default => 'remote_download_failed',
                });
            }
            $exit = $result->exitCode;
            if ($result->exitCode !== 0) {
                $this->diagnostic('failed', $exit, $started, $result->stderr);
                throw MediaValidationException::withCode($this->failureCode($result->stderr));
            }

            $file = $directory . DIRECTORY_SEPARATOR . 'media.mp4';
            if (!is_file($file)) {
                // yt-dlp encerra com sucesso sem gravar nada quando o arquivo passa de --max-filesize.
                $this->diagnostic('no_output', $exit, $started, $result->stderr . "\n" . $result->stdout);
                throw MediaValidationException::withCode('media_too_large');
            }
            clearstatcache(true, $file);
            $size = filesize($file);
            if ($size === false || $size < 1) {
                throw MediaValidationException::withCode('remote_download_failed');
            }
            if ($size > $maxBytes) {
                throw MediaValidationException::withCode('media_too_large');
            }
            $this->validator->validatePath($file, 'remote.mp4', $size);

            $stream = @fopen($file, 'rb');
            if ($stream === false) {
                throw MediaValidationException::withCode('storage_write_failed');
            }
            try {
                $object = $storage->putStream($stream, $objectKey, $maxBytes);
            } finally {
                fclose($stream);
            }
            $this->diagnostic('downloaded', $exit, $started, '', $size);

            return $object;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** @return list<string> */
    private function command(ResolvedYoutubeMedia $media, string $source, string $directory, int $maxBytes): array
    {
        $selector = $media->formatSelector();
        $command = [
            $this->binary,
            '--ignore-config',
            '--no-plugin-dirs',
        ];
        if (is_string($this->cacheDirectory) && trim($this->cacheDirectory) !== '' && is_dir(trim($this->cacheDirectory))) {
            array_push($command, '--cache-dir', trim($this->cacheDirectory));
        } else {
            $command[] = '--no-cache-dir';
        }
        $command[] = '--no-js-runtimes';
        if (is_string($this->javascriptRuntime) && trim($this->javascriptRuntime) !== '') {
            array_push($command, '--js-runtimes', trim($this->javascriptRuntime));
        }
        if (is_string($this->remoteComponents) && preg_match('/^[a-z]+:[a-z]+$/', trim($this->remoteComponents)) === 1) {
            array_push($command, '--remote-components', trim($this->remoteComponents));
        } else {
            $command[] = '--no-remote-components';
        }
        if (is_string($this->cookiesFile) && trim($this->cookiesFile) !== '' && is_readable(trim($this->cookiesFile))) {
            array_push($command, '--cookies', trim($this->cookiesFile));
        }
        if ($this->forceIpv4) {
            $command[] = '--force-ipv4';
        }
        if (is_string($this->ffmpegBinary) && (str_contains($this->ffmpegBinary, '/') || str_contains($this->ffmpegBinary, '\\'))) {
            array_push($command, '--ffmpeg-location', $this->ffmpegBinary);
        }
        array_push(
            $command,
            '--no-playlist',
            '--use-extractors', 'youtube',
            '--quiet', '--no-warnings', '--no-progress', '--no-mtime',
            '--socket-timeout', '30',
            '--retries', '3',
            '--fragment-retries', '3',
            '--format', $selector !== null ? $selector . '/' . self::DEFAULT_SELECTOR : self::DEFAULT_SELECTOR,
            '--merge-output-format', 'mp4',
            '--max-filesize', (string) $maxBytes,
            '--output', $directory . DIRECTORY_SEPARATOR . 'media.%(ext)s',
            '--',
            $source
        );

        return $command;
    }

    private function failureCode(string $stderr): string
    {
        $code = YoutubeFailureClassifier::fromStderr($stderr);
        // Falhas genéricas no download (ex.: HTTP 403 do googlevideo) são tratadas como
        // temporárias: o job tenta de novo, com um endereço novo.
        return $code === 'youtube_video_unavailable' ? 'remote_download_failed' : $code;
    }

    private function diagnostic(string $result, ?int $exit, float $started, string $output, int $bytes = 0): void
    {
        if (!is_callable($this->report)) {
            return;
        }
        $line = '';
        foreach (preg_split('/\R/', $output) ?: [] as $candidate) {
            if (str_starts_with(trim($candidate), 'ERROR')) {
                $line = trim($candidate);
                break;
            }
        }
        $line = mb_substr((string) preg_replace('#https?://\S+#', '[url]', $line), 0, 300);
        try {
            ($this->report)([
                'result' => $result,
                'exit_code' => $exit,
                'bytes' => $bytes,
                'seconds' => (int) round(microtime(true) - $started),
                'error' => $line,
            ]);
        } catch (\Throwable) {
            // Diagnóstico nunca interrompe a importação.
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
