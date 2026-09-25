<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\ResolvedYoutubeMedia;
use App\Media\UploadValidator;
use App\Media\ValidatedRemoteUrl;
use App\Media\YtDlpMediaFetcher;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class YtDlpMediaFetcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ytdlp-fetcher-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testDownloadsWithTheChosenFormatAndStoresAValidatedMp4(): void
    {
        $runner = new FetcherRunner($this->root, $this->mp4());
        $fetcher = $this->fetcher($runner);

        $object = $fetcher->fetch($this->media('18'), $this->storage(), 'imports/7/video.mp4', 10485760);

        self::assertSame('imports/7/video.mp4', $object->objectKey());
        self::assertSame(strlen($this->mp4()), $object->sizeBytes());
        $format = $runner->command[array_search('--format', $runner->command, true) + 1];
        self::assertStringStartsWith('18/', $format);
        self::assertContains('--cookies', $runner->command);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', end($runner->command));
        self::assertSame([], glob($this->root . '/.ytdlp-*'), 'The work directory must be removed.');
    }

    public function testMissingOutputMeansTheVideoExceededTheSizeLimit(): void
    {
        $this->expectFailure('media_too_large', new FetcherRunner($this->root, null));
    }

    public function testGenericDownloadErrorsAreRetryable(): void
    {
        $runner = new FetcherRunner($this->root, null, new ProcessResult(1, '', 'ERROR: unable to download video data: HTTP Error 403: Forbidden'));
        $this->expectFailure('remote_download_failed', $runner);
    }

    public function testKnownYoutubeFailuresKeepTheirSpecificCode(): void
    {
        $runner = new FetcherRunner($this->root, null, new ProcessResult(1, '', 'ERROR: Sign in to confirm you’re not a bot'));
        $this->expectFailure('youtube_bot_challenge', $runner);
    }

    public function testRejectsDownloadedFilesThatAreNotMp4(): void
    {
        $this->expectFailure('invalid_media_container', new FetcherRunner($this->root, 'not a video'));
    }

    private function expectFailure(string $code, FetcherRunner $runner): void
    {
        try {
            $this->fetcher($runner)->fetch($this->media('18'), $this->storage(), 'imports/7/video.mp4', 10485760);
            self::fail('The fetch should fail.');
        } catch (MediaValidationException $exception) {
            self::assertSame($code, $exception->publicCode());
        }
        self::assertSame([], glob($this->root . '/.ytdlp-*'));
    }

    private function fetcher(FetcherRunner $runner): YtDlpMediaFetcher
    {
        $cookies = $this->root . '/cookies.txt';
        file_put_contents($cookies, "# Netscape HTTP Cookie File\n");

        return new YtDlpMediaFetcher(
            $runner,
            new UploadValidator(10485760, static fn (string $path): string => str_contains((string) file_get_contents($path), 'ftyp') ? 'video/mp4' : 'text/plain'),
            $this->root,
            'yt-dlp',
            60,
            'node:/usr/local/bin/node',
            true,
            $cookies,
            'ejs:github'
        );
    }

    private function storage(): LocalPrivateStorage
    {
        return new LocalPrivateStorage($this->root . '/media', 10485760);
    }

    private function media(string $format): ResolvedYoutubeMedia
    {
        return (new ResolvedYoutubeMedia(new ValidatedRemoteUrl(
            'https://rr1---sn.example.googlevideo.com/videoplayback?expire=1',
            'rr1---sn.example.googlevideo.com',
            ['142.250.1.1']
        )))->withSource('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $format);
    }

    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2" . str_repeat("\x00", 64);
    }
}

final class FetcherRunner extends ProcessRunner
{
    /** @var list<string> */
    public array $command = [];

    public function __construct(private string $root, private ?string $output, private ?ProcessResult $result = null)
    {
        parent::__construct(['yt-dlp'], $root);
    }

    public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
    {
        $this->command = $command;
        $template = $command[array_search('--output', $command, true) + 1];
        if ($this->output !== null) {
            file_put_contents(str_replace('%(ext)s', 'mp4', $template), $this->output);
        }

        return $this->result ?? new ProcessResult(0, '', '');
    }
}
