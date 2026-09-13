<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use App\Media\PinnedHttpDownloader;
use App\Media\UploadValidator;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class PinnedHttpDownloaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pinned-downloader-' . bin2hex(random_bytes(6));
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

    public function testRejectsRedirectToMetadataAddressBeforeAnySecondRequest(): void
    {
        $requests = [];
        $validator = new DirectUrlValidator(static fn (string $host): array => $host === 'metadata.example.test' ? ['169.254.169.254'] : ['1.1.1.1']);
        $downloader = new PinnedHttpDownloader(
            $validator,
            new UploadValidator(1024, static fn (string $path): string => 'video/mp4'),
            10,
            2,
            $this->storage(),
            function ($url, int $timeout) use (&$requests): array {
                $requests[] = $url->url();
                return ['status' => 302, 'headers' => ['location' => 'https://metadata.example.test/latest'], 'stream' => null];
            }
        );

        try {
            $downloader->download($validator->validate('https://cdn.example.test/video.mp4'), $this->storage(), 'object.mp4', 1024);
            self::fail('Redirect to metadata service was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('unsafe_source_url', $exception->publicCode());
        }
        self::assertSame(['https://cdn.example.test/video.mp4'], $requests);
    }

    public function testRejectsOversizedOrMalformedContentLengthBeforeWriting(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        $downloader = new PinnedHttpDownloader(
            $validator,
            new UploadValidator(1024, static fn (string $path): string => 'video/mp4'),
            10,
            0,
            $this->storage(),
            static fn ($url, int $timeout): array => ['status' => 200, 'headers' => ['content-length' => 'not-a-number', 'content-type' => 'video/mp4'], 'stream' => fopen('php://temp', 'w+b')]
        );

        try {
            $downloader->download($validator->validate('https://cdn.example.test/video.mp4'), $this->storage(), 'object.mp4', 64);
            self::fail('Malformed content length was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('remote_content_length', $exception->publicCode());
        }
    }

    public function testRejectsHtmlResponseEvenWhenTheUrlEndsInMp4(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '<html>not a video</html>');
        rewind($stream);
        $downloader = new PinnedHttpDownloader(
            $validator,
            new UploadValidator(1024, static fn (string $path): string => 'text/html'),
            10,
            0,
            $this->storage(),
            static fn ($url, int $timeout): array => ['status' => 200, 'headers' => ['content-length' => '24', 'content-type' => 'text/html'], 'stream' => $stream]
        );

        try {
            $downloader->download($validator->validate('https://cdn.example.test/video.mp4'), $this->storage(), 'object.mp4', 1024);
            self::fail('HTML response was stored as a video.');
        } catch (MediaValidationException $exception) {
            self::assertSame('invalid_media_container', $exception->publicCode());
        }
    }

    private function storage(): LocalPrivateStorage
    {
        return new LocalPrivateStorage($this->directory, 1024, static fn (string $path): bool => true);
    }
}
