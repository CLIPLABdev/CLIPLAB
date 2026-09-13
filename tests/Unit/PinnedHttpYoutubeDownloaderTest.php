<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use App\Media\PinnedHttpDownloader;
use App\Media\ResolvedYoutubeMedia;
use App\Media\ResolvedYoutubeTrack;
use App\Media\ValidatedRemoteUrl;
use App\Media\UploadValidator;
use App\Process\ProcessResult;
use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;
use App\Media\StoredObject;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class PinnedHttpYoutubeDownloaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pinned-youtube-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
        }
        @rmdir($this->directory);
    }

    public function testDownloadsAnExtensionlessResolvedYoutubeMp4ThroughThePinnedByteLimitedPath(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        $bytes = "\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 20);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $storage = new LocalPrivateStorage($this->directory, 1024, static fn (string $path): bool => true);
        $downloader = new PinnedHttpDownloader(
            $validator,
            new UploadValidator(1024, static fn (string $path): string => 'video/mp4'),
            10,
            0,
            $storage,
            static fn ($url, int $timeout): array => [
                'status' => 200,
                'headers' => ['content-length' => (string) strlen($bytes), 'content-type' => 'video/mp4'],
                'stream' => $stream,
            ]
        );
        $resolved = new ResolvedYoutubeMedia(
            $validator->validate('https://rr1---sn.example.googlevideo.com/videoplayback?expire=1')
        );

        $object = $downloader->downloadYoutube($resolved, $storage, 'imports/1/source.mp4', 1024);

        self::assertSame(strlen($bytes), $object->sizeBytes());
        self::assertFileExists($this->directory . '/imports/1/source.mp4');
    }

    public function testDownloadsAdaptiveTracksWithinOneBudgetAndMergesOnlyLocalPaths(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        $videoBytes = "\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 24);
        $audioBytes = "\x00\x00\x00\x18ftypM4A " . str_repeat("\x00", 16);
        $payloads = [[$videoBytes, 'video/mp4'], [$audioBytes, 'audio/mp4']];
        $calls = 0;
        $storage = new LocalPrivateStorage($this->directory, 2048, static fn (string $path): bool => true);
        $runner = new YoutubeMergeRunner($this->directory, $videoBytes);
        $downloader = new PinnedHttpDownloader(
            $validator,
            new UploadValidator(2048, static fn (string $path): string => 'video/mp4'),
            10,
            0,
            $storage,
            static function ($url, int $timeout) use (&$calls, $payloads): array {
                [$bytes, $mime] = $payloads[$calls++];
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $bytes);
                rewind($stream);
                return [
                    'status' => 200,
                    'headers' => ['content-length' => (string) strlen($bytes), 'content-type' => $mime],
                    'stream' => $stream,
                ];
            },
            $runner,
            'ffmpeg',
            15,
            8192
        );
        $media = ResolvedYoutubeMedia::adaptive(
            new ResolvedYoutubeTrack($validator->validate('https://v.example.googlevideo.com/videoplayback?v=1'), 'video', 'mp4', 'video/mp4'),
            new ResolvedYoutubeTrack($validator->validate('https://a.example.googlevideo.com/videoplayback?a=1'), 'audio', 'm4a', 'audio/mp4')
        );

        $object = $downloader->downloadYoutube($media, $storage, 'imports/1/adaptive.mp4', 1024);

        self::assertSame(2, $calls);
        self::assertFileExists($this->directory . '/imports/1/adaptive.mp4');
        self::assertSame(strlen($videoBytes), $object->sizeBytes());
        self::assertContains('file,pipe', $runner->command);
        self::assertSame(2, count(array_filter($runner->command, static fn (string $arg): bool => $arg === 'file,pipe')));
        self::assertContains('1024', $runner->command);
        self::assertSame([], array_values(array_filter($runner->command, static fn (string $arg): bool => str_contains($arg, 'https://'))));
    }

    public function testSecondAdaptiveTrackCannotExceedTheSharedBudgetAndAllStagingIsRemoved(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        $bytes = "\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 24);
        $calls = 0;
        $storage = new LocalPrivateStorage($this->directory, 64, static fn (string $path): bool => true);
        $downloader = new PinnedHttpDownloader(
            $validator,
            new UploadValidator(64, static fn (string $path): string => 'video/mp4'),
            10,
            0,
            $storage,
            static function ($url, int $timeout) use (&$calls, $bytes): array {
                $calls++;
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $bytes);
                rewind($stream);
                return [
                    'status' => 200,
                    'headers' => ['content-length' => (string) strlen($bytes), 'content-type' => $calls === 1 ? 'video/mp4' : 'audio/mp4'],
                    'stream' => $stream,
                ];
            },
            new YoutubeMergeRunner($this->directory, $bytes)
        );
        $media = ResolvedYoutubeMedia::adaptive(
            new ResolvedYoutubeTrack($validator->validate('https://v.example.googlevideo.com/videoplayback?v=1'), 'video', 'mp4', 'video/mp4'),
            new ResolvedYoutubeTrack($validator->validate('https://a.example.googlevideo.com/videoplayback?a=1'), 'audio', 'm4a', 'audio/mp4')
        );

        try {
            $downloader->downloadYoutube($media, $storage, 'imports/1/too-large.mp4', 50);
            self::fail('The two tracks exceeded their aggregate input budget.');
        } catch (\App\Exceptions\MediaValidationException $exception) {
            self::assertSame('media_too_large', $exception->publicCode());
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path) {
            if ($path->isFile()) {
                $files[] = $path->getPathname();
            }
        }
        self::assertSame(2, $calls);
        self::assertSame([], $files);
    }

    public function testYoutubeRedirectCannotLeaveGooglevideoHosts(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        foreach ([
            'off-suffix host' => 'https://public.example.test/redirected.mp4',
            'googlevideo custom port' => 'https://rr2.example.googlevideo.com:444/videoplayback',
        ] as $case => $location) {
            $calls = 0;
            $storage = new LocalPrivateStorage($this->directory, 1024, static fn (string $path): bool => true);
            $downloader = new PinnedHttpDownloader(
                $validator,
                new UploadValidator(1024, static fn (string $path): string => 'video/mp4'),
                10,
                1,
                $storage,
                static function ($url, int $timeout) use (&$calls, $location): array {
                    $calls++;
                    return [
                        'status' => 302,
                        'headers' => ['location' => $location],
                        'stream' => null,
                    ];
                }
            );
            $resolved = new ResolvedYoutubeMedia(
                $validator->validate('https://rr1.example.googlevideo.com/videoplayback?expire=1')
            );

            try {
                $downloader->downloadYoutube($resolved, $storage, 'imports/1/redirect.mp4', 1024);
                self::fail('An unsafe YouTube CDN redirect was accepted: ' . $case);
            } catch (MediaValidationException $exception) {
                self::assertSame('youtube_response_invalid', $exception->publicCode(), $case);
            }
            self::assertSame(1, $calls, $case);
        }
    }

    public function testAdaptiveFailuresCleanAllStagingFiles(): void
    {
        $validBytes = "\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 24);
        $cases = [
            'ffmpeg timeout' => [new YoutubeMergeRunner($this->directory, $validBytes, new ProcessExecutionException('process_timeout')), null, 'remote_timeout'],
            'ffmpeg nonzero exit' => [new YoutubeMergeRunner($this->directory, $validBytes, null, 1), null, 'invalid_media_container'],
            'merged validation failure' => [new YoutubeMergeRunner($this->directory, 'not-an-mp4'), null, 'invalid_media_container'],
            'storage failure' => [new YoutubeMergeRunner($this->directory, $validBytes), new FailingYoutubeStorage(), 'storage_write_failed'],
        ];

        foreach ($cases as $case => [$runner, $targetStorage, $expectedCode]) {
            $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
            $staging = new LocalPrivateStorage($this->directory, 2048, static fn (string $path): bool => true);
            $calls = 0;
            $downloader = new PinnedHttpDownloader(
                $validator,
                new UploadValidator(2048, static fn (string $path): string => 'video/mp4'),
                10,
                0,
                $staging,
                static function ($url, int $timeout) use (&$calls, $validBytes): array {
                    $calls++;
                    $stream = fopen('php://temp', 'w+b');
                    fwrite($stream, $validBytes);
                    rewind($stream);
                    return [
                        'status' => 200,
                        'headers' => ['content-length' => (string) strlen($validBytes), 'content-type' => $calls === 1 ? 'video/mp4' : 'audio/mp4'],
                        'stream' => $stream,
                    ];
                },
                $runner
            );
            $media = ResolvedYoutubeMedia::adaptive(
                new ResolvedYoutubeTrack($validator->validate('https://v.example.googlevideo.com/videoplayback?v=1'), 'video', 'mp4', 'video/mp4'),
                new ResolvedYoutubeTrack($validator->validate('https://a.example.googlevideo.com/videoplayback?a=1'), 'audio', 'm4a', 'audio/mp4')
            );

            try {
                $downloader->downloadYoutube($media, $targetStorage ?? $staging, 'imports/1/failure.mp4', 1024);
                self::fail('Adaptive failure was accepted: ' . $case);
            } catch (MediaValidationException $exception) {
                self::assertSame($expectedCode, $exception->publicCode(), $case);
            }
            self::assertSame([], $this->filesInDirectory(), $case);
        }
    }

    /** @return list<string> */
    private function filesInDirectory(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path) {
            if ($path->isFile()) {
                $files[] = $path->getPathname();
            }
        }
        return $files;
    }
}

final class YoutubeMergeRunner extends ProcessRunner
{
    /** @var list<string> */
    public array $command = [];

    public function __construct(
        string $directory,
        private string $outputBytes,
        private ?ProcessExecutionException $exception = null,
        private int $exitCode = 0
    )
    {
        parent::__construct(['ffmpeg'], $directory);
    }

    public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
    {
        $this->command = $command;
        if ($this->exception !== null) {
            throw $this->exception;
        }
        $output = $command[count($command) - 1];
        file_put_contents($output, $this->outputBytes);
        return new ProcessResult($this->exitCode, '', '');
    }
}

final class FailingYoutubeStorage implements PrivateStorage
{
    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
    {
        throw new \LogicException('Not used.');
    }

    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
    {
        throw MediaValidationException::withCode('storage_write_failed');
    }

    public function absolutePath(string $objectKey): string
    {
        throw new \LogicException('Not used.');
    }

    public function delete(string $objectKey): void
    {
    }
}
