<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\{CurlDownloadTransport, DirectUrlValidator, DownloadRequest, DownloadResponse, DownloadTransport, PinnedHttpDownloader, ResolvedYoutubeMedia, ResolvedYoutubeTrack, UploadValidator};
use App\Process\{ProcessRunner, ProcessResult};
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class YoutubeRangeDownloadTest extends TestCase
{
    private string $directory;
    private LocalPrivateStorage $storage;
    private DirectUrlValidator $validator;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/youtube-ranges-' . bin2hex(random_bytes(6));
        $this->storage = new LocalPrivateStorage($this->directory, 30000000, static fn (): bool => true);
        $this->validator = new DirectUrlValidator(static fn (): array => ['1.1.1.1']);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) { return; }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($this->directory);
    }

    public function testAssemblesContiguousYoutubeRangesAndEmitsOnlyInternalNumericRangeHeaders(): void
    {
        $bytes = $this->mediaBytes(10485792);
        $transport = new RangeFixtureTransport(function (DownloadRequest $request, callable $write, int $call) use ($bytes): DownloadResponse {
            $options = (new CurlDownloadTransport())->optionsFor($request, static fn (): int => 0, static fn (): int => 0);
            self::assertSame($call === 1 ? '0-10485759' : '10485760-10485791', $options[CURLOPT_RANGE] ?? null);
            self::assertSame(['cdn.googlevideo.com:443:1.1.1.1'], $options[CURLOPT_RESOLVE]);
            $body = $call === 1 ? substr($bytes, 0, 10485760) : substr($bytes, 10485760);
            $write($body);
            return new DownloadResponse(206, ['content-type' => 'video/mp4', 'content-length' => (string) strlen($body), 'content-range' => $call === 1 ? 'bytes 0-10485759/10485792' : 'bytes 10485760-10485791/10485792']);
        });
        $this->download($transport, 20000000);
        self::assertSame($bytes, file_get_contents($this->directory . '/imports/source.mp4'));
        self::assertSame(2, $transport->calls);
    }

    /** @dataProvider invalidFirstRanges */
    public function testRejectsInvalidFirstRangesAndRemovesStaging(string $range, string $length, string $expected): void
    {
        $transport = new RangeFixtureTransport(function ($request, $write) use ($range, $length): DownloadResponse {
            $write($this->mediaBytes(32));
            return new DownloadResponse(206, ['content-type' => 'video/mp4', 'content-range' => $range, 'content-length' => $length]);
        });
        $this->assertRejected($transport, 1024, $expected);
    }

    public function invalidFirstRanges(): array
    {
        return [
            'missing' => ['', '32', 'remote_download_failed'],
            'gap' => ['bytes 1-32/33', '32', 'remote_download_failed'],
            'short body' => ['bytes 0-32/33', '32', 'remote_download_failed'],
            'short declared length' => ['bytes 0-31/32', '31', 'remote_download_failed'],
            'unknown total' => ['bytes 0-31/*', '32', 'remote_download_failed'],
            'beyond end' => ['bytes 0-32/32', '32', 'remote_download_failed'],
            'over budget total' => ['bytes 0-31/1025', '32', 'media_too_large'],
            'overflow total' => ['bytes 0-31/99999999999999999999999999', '32', 'media_too_large'],
            'duplicate header' => ['bytes 0-31/32,bytes 0-31/32', '32', 'remote_download_failed'],
        ];
    }

    /** @dataProvider invalidLaterRanges */
    public function testRejectsChangedOrIgnoredLaterRanges(int $status, string $range): void
    {
        $transport = new RangeFixtureTransport(function ($request, $write, int $call) use ($status, $range): DownloadResponse {
            $write($this->mediaBytes($call === 1 ? 10485760 : 32));
            return new DownloadResponse($call === 1 ? 206 : $status, ['content-type' => 'video/mp4', 'content-range' => $call === 1 ? 'bytes 0-10485759/10485792' : $range]);
        });
        $this->assertRejected($transport, 20000000, 'remote_download_failed');
        self::assertSame(2, $transport->calls);
    }

    public function invalidLaterRanges(): array
    {
        return [
            'ignored range' => [200, ''],
            'changed total' => [206, 'bytes 10485760-10485791/10485793'],
            'overlap' => [206, 'bytes 10485759-10485790/10485792'],
        ];
    }

    public function testRedirectBodiesAreDiscardedAndNewHostIsPinnedBeforeContinuing(): void
    {
        $transport = new RangeFixtureTransport(function ($request, $write, int $call): DownloadResponse {
            if ($call === 1) {
                $write('redirect body');
                return new DownloadResponse(302, ['location' => 'https://next.googlevideo.com/videoplayback']);
            }
            self::assertSame('next.googlevideo.com', $request->url()->host());
            self::assertSame(['1.1.1.1'], $request->url()->addresses());
            $write($this->mediaBytes(32));
            return new DownloadResponse(206, ['content-type' => 'video/mp4', 'content-range' => 'bytes 0-31/32']);
        });
        $this->download($transport, 1024);
        self::assertSame($this->mediaBytes(32), file_get_contents($this->directory . '/imports/source.mp4'));
    }

    public function testOrdinaryUrlDoesNotRequestRanges(): void
    {
        $transport = new RangeFixtureTransport(function ($request, $write): DownloadResponse {
            $options = (new CurlDownloadTransport())->optionsFor($request, static fn (): int => 0, static fn (): int => 0);
            self::assertArrayNotHasKey(CURLOPT_RANGE, $options);
            $write($this->mediaBytes(32));
            return new DownloadResponse(200, ['content-type' => 'video/mp4']);
        });
        $this->downloader($transport)->downloadStoredUrl('https://public.example/video.mp4', $this->storage, 'imports/source.mp4', 1024);
        self::assertSame(32, filesize($this->directory . '/imports/source.mp4'));
    }

    public function testAdaptivePartialResponsesAreValidatedBeforeLocalMerge(): void
    {
        $video = $this->mediaBytes(32);
        $audio = "\x00\x00\x00\x18ftypM4A " . str_repeat("\x00", 20);
        $transport = new RangeFixtureTransport(function ($request, $write, int $call) use ($video, $audio): DownloadResponse {
            $write($call === 1 ? $video : $audio);
            return new DownloadResponse(206, ['content-type' => $call === 1 ? 'video/mp4' : 'audio/mp4', 'content-range' => 'bytes 0-31/32']);
        });
        $runner = new class($this->directory, $video, $audio) extends ProcessRunner {
            public function __construct(string $directory, private string $video, private string $audio) { parent::__construct(['ffmpeg'], $directory); }
            public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
            {
                $inputs = [];
                foreach ($command as $index => $argument) {
                    if ($argument === '-i') { $inputs[] = file_get_contents($command[$index + 1]); }
                }
                TestCase::assertSame([$this->video, $this->audio], $inputs);
                file_put_contents($command[count($command) - 1], $this->video);
                return new ProcessResult(0, '', '');
            }
        };
        $downloader = new PinnedHttpDownloader($this->validator, new UploadValidator(1024, static fn (): string => 'video/mp4'), 10, 1, $this->storage, $transport, $runner);
        $media = ResolvedYoutubeMedia::adaptive(
            new ResolvedYoutubeTrack($this->validator->validate('https://cdn.googlevideo.com/video'), 'video', 'mp4', 'video/mp4'),
            new ResolvedYoutubeTrack($this->validator->validate('https://cdn.googlevideo.com/audio'), 'audio', 'm4a', 'audio/mp4')
        );
        $downloader->downloadYoutube($media, $this->storage, 'imports/source.mp4', 1024);
        self::assertSame($video, file_get_contents($this->directory . '/imports/source.mp4'));
    }

    public function testRejectsPrivateRedirectAddressesBeforeConnecting(): void
    {
        $this->validator = new DirectUrlValidator(static fn (string $host): array => $host === 'cdn.googlevideo.com' ? ['1.1.1.1'] : ['127.0.0.1']);
        $transport = new RangeFixtureTransport(static fn (): DownloadResponse => new DownloadResponse(302, ['location' => 'https://next.googlevideo.com/videoplayback']));
        $this->assertRejected($transport, 1024, 'unsafe_source_url');
        self::assertSame(1, $transport->calls);
    }

    public function testResponseCannotExceedTheRequestedRangeBudget(): void
    {
        $transport = new RangeFixtureTransport(function ($request, $write): DownloadResponse {
            $write($this->mediaBytes(10485761));
            return new DownloadResponse(206, ['content-type' => 'video/mp4', 'content-range' => 'bytes 0-10485760/10485761']);
        });
        $this->assertRejected($transport, 20000000, 'media_too_large');
    }

    public function testOneDeadlineIncludesTransferTimeBeforePublishing(): void
    {
        $transport = new RangeFixtureTransport(function ($request, $write): DownloadResponse {
            usleep(1100000);
            $write($this->mediaBytes(32));
            return new DownloadResponse(206, ['content-type' => 'video/mp4', 'content-range' => 'bytes 0-31/32']);
        });
        $downloader = new PinnedHttpDownloader($this->validator, new UploadValidator(1024, static fn (): string => 'video/mp4'), 1, 1, $this->storage, $transport);
        $media = new ResolvedYoutubeMedia($this->validator->validate('https://cdn.googlevideo.com/video'));
        try { $downloader->downloadYoutube($media, $this->storage, 'imports/source.mp4', 1024); self::fail('Transfer exceeded its deadline.'); }
        catch (MediaValidationException $exception) { self::assertSame('remote_timeout', $exception->publicCode()); }
        self::assertFileDoesNotExist($this->directory . '/imports/source.mp4');
    }

    private function assertRejected(DownloadTransport $transport, int $limit, string $code): void
    {
        try { $this->download($transport, $limit); self::fail('Invalid ranged media was published.'); }
        catch (MediaValidationException $exception) { self::assertSame($code, $exception->publicCode()); }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) { self::assertFalse($file->isFile(), 'Failed download left a file behind.'); }
    }

    private function download(DownloadTransport $transport, int $limit): void
    {
        $media = new ResolvedYoutubeMedia($this->validator->validate('https://cdn.googlevideo.com/videoplayback'));
        $this->downloader($transport)->downloadYoutube($media, $this->storage, 'imports/source.mp4', $limit);
    }

    private function downloader(DownloadTransport $transport): PinnedHttpDownloader
    {
        return new PinnedHttpDownloader($this->validator, new UploadValidator(30000000, static fn (): string => 'video/mp4'), 10, 1, $this->storage, $transport);
    }

    private function mediaBytes(int $length): string
    {
        return "\x00\x00\x00\x18ftypisom" . str_repeat("\x00", $length - 12);
    }
}

final class RangeFixtureTransport implements DownloadTransport
{
    public int $calls = 0;
    public function __construct(private \Closure $handler) {}
    public function download(DownloadRequest $request, callable $onChunk): DownloadResponse
    {
        return ($this->handler)($request, $onChunk, ++$this->calls);
    }
}
