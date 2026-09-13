<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use App\Media\DownloadRequest;
use App\Media\DownloadResponse;
use App\Media\DownloadTransport;
use App\Media\PinnedHttpDownloader;
use App\Media\StoredObject;
use App\Media\UploadValidator;
use App\Storage\LocalPrivateStorage;
use App\Storage\PrivateStagingArea;
use App\Storage\PrivateStagingFile;
use PHPUnit\Framework\TestCase;

final class PinnedHttpDownloaderTransportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pinned-transport-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
        }
        @rmdir($this->root);
    }

    public function testPassesPinnedRequestToDedicatedTransportAndPromotesValidMedia(): void
    {
        $transport = new RecordingDownloadTransport([
            ['status' => 200, 'headers' => ['content-type' => 'video/mp4'], 'body' => $this->mp4()],
        ]);
        $validator = $this->urlValidator();
        $downloader = new PinnedHttpDownloader($validator, $this->uploads(), 10, 1, new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), $transport);
        $storage = new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true);

        $stored = $downloader->download($validator->validate('https://cdn.example.test/movie.mp4'), $storage, 'users/9/movie.mp4', 1024);

        self::assertSame('users/9/movie.mp4', $stored->objectKey());
        self::assertCount(1, $transport->requests);
        self::assertSame('cdn.example.test', $transport->requests[0]->url()->host());
        self::assertSame(['1.1.1.1', '2606:4700:4700::1111'], $transport->requests[0]->url()->addresses());
        self::assertSame(1024, $transport->requests[0]->maxBytes());
        self::assertFileExists($storage->absolutePath($stored->objectKey()));
        self::assertSame([], glob($this->root . '/users/9/*.download.part') ?: []);
    }

    public function testMissingContentLengthStillStreamsAValidContainer(): void
    {
        $transport = new RecordingDownloadTransport([
            ['status' => 200, 'headers' => ['content-type' => 'video/mp4'], 'body' => $this->mp4()],
        ]);
        $validator = $this->urlValidator();
        $downloader = new PinnedHttpDownloader($validator, $this->uploads(), 10, 0, new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), $transport);

        $stored = $downloader->download($validator->validate('https://cdn.example.test/movie.mp4'), new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), 'users/9/no-length.mp4', 1024);

        self::assertGreaterThan(0, $stored->sizeBytes());
    }

    /** @dataProvider invalidContentLengths */
    public function testRejectsMalformedAndOversizedContentLengthBeforePromotion(string $contentLength, string $expectedCode): void
    {
        $transport = new RecordingDownloadTransport([
            ['status' => 200, 'headers' => ['content-type' => 'video/mp4', 'content-length' => $contentLength], 'body' => $this->mp4()],
        ]);
        $validator = $this->urlValidator();
        $downloader = new PinnedHttpDownloader($validator, $this->uploads(), 10, 0, new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), $transport);
        $storage = new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true);

        try {
            $downloader->download($validator->validate('https://cdn.example.test/movie.mp4'), $storage, 'users/9/length.mp4', 64);
            self::fail('Invalid Content-Length was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame($expectedCode, $exception->publicCode());
        }
        self::assertFileDoesNotExist($storage->absolutePath('users/9/length.mp4'));
        self::assertSame([], glob($this->root . '/users/9/*.download.part') ?: []);
    }

    /** @return iterable<string, array{string, string}> */
    public function invalidContentLengths(): iterable
    {
        yield 'malformed' => ['not-a-number', 'remote_content_length'];
        yield 'negative' => ['-1', 'remote_content_length'];
        yield 'oversized' => ['65', 'media_too_large'];
    }

    public function testStopsAStreamThatExceedsTheLimitAndCleansPrivateStaging(): void
    {
        $transport = new RecordingDownloadTransport([
            ['status' => 200, 'headers' => ['content-type' => 'video/mp4'], 'body' => $this->mp4() . str_repeat('x', 128)],
        ]);
        $validator = $this->urlValidator();
        $storage = new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true);
        $downloader = new PinnedHttpDownloader($validator, $this->uploads(), 10, 0, new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), $transport);

        try {
            $downloader->download($validator->validate('https://cdn.example.test/movie.mp4'), $storage, 'users/9/large.mp4', 32);
            self::fail('An over-limit response body was promoted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('media_too_large', $exception->publicCode());
        }
        self::assertFileDoesNotExist($storage->absolutePath('users/9/large.mp4'));
        self::assertSame([], glob($this->root . '/users/9/*.download.part') ?: []);
    }

    public function testRejectsRedirectAfterTheConfiguredBudget(): void
    {
        $transport = new RecordingDownloadTransport([
            ['status' => 302, 'headers' => ['location' => '/second.mp4'], 'body' => ''],
            ['status' => 302, 'headers' => ['location' => '/third.mp4'], 'body' => ''],
        ]);
        $validator = $this->urlValidator();
        $downloader = new PinnedHttpDownloader($validator, $this->uploads(), 10, 1, new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), $transport);

        try {
            $downloader->download($validator->validate('https://cdn.example.test/first.mp4'), new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), 'users/9/redirect.mp4', 1024);
            self::fail('Redirect beyond the budget was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('remote_redirect_rejected', $exception->publicCode());
        }
        self::assertCount(2, $transport->requests);
    }

    public function testRejectsAnInvalidContainerBeforePromotionInsteadOfUsingLegacyPutStream(): void
    {
        $transport = new RecordingDownloadTransport([
            ['status' => 200, 'headers' => ['content-type' => 'video/mp4'], 'body' => '<html>not a video</html>'],
        ]);
        $validator = $this->urlValidator();
        $storage = new TrackingStagingStorage($this->root);
        $downloader = new PinnedHttpDownloader($validator, $this->uploads(), 10, 0, new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true), $transport);

        try {
            $downloader->download($validator->validate('https://cdn.example.test/movie.mp4'), $storage, 'movie.mp4', 1024);
            self::fail('A response without a media signature was promoted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('invalid_media_container', $exception->publicCode());
        }
        self::assertFalse($storage->legacyPutStreamCalled);
        self::assertSame(0, $storage->promotions);
        self::assertSame([], glob($this->root . '/*.download.part') ?: []);
    }

    private function urlValidator(): DirectUrlValidator
    {
        return new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1', '2606:4700:4700::1111']);
    }

    private function uploads(): UploadValidator
    {
        return new UploadValidator(1024, static fn (string $path): string => 'video/mp4');
    }

    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41";
    }
}

final class RecordingDownloadTransport implements DownloadTransport
{
    /** @var list<DownloadRequest> */
    public array $requests = [];
    /** @var list<array{status: int, headers: array<string, string>, body: string}> */
    private array $responses;

    /** @param list<array{status: int, headers: array<string, string>, body: string}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /** @param callable(string): void $onChunk */
    public function download(DownloadRequest $request, callable $onChunk): DownloadResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if ($response === null) {
            throw MediaValidationException::withCode('remote_download_failed');
        }
        if ($response['body'] !== '') {
            $onChunk($response['body']);
        }
        return new DownloadResponse($response['status'], $response['headers']);
    }

    /** @return array{status: int, headers: array<string, string>, stream: resource} */
    public function __invoke(mixed $url, int $timeout): array
    {
        $response = array_shift($this->responses);
        if ($response === null) {
            throw MediaValidationException::withCode('remote_download_failed');
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $response['body']);
        rewind($stream);
        return ['status' => $response['status'], 'headers' => $response['headers'], 'stream' => $stream];
    }
}

final class TrackingStagingStorage implements PrivateStorage, PrivateStagingArea
{
    public bool $legacyPutStreamCalled = false;
    public int $promotions = 0;
    /** @var array<int, PrivateStagingFile> */
    private array $staging = [];

    public function __construct(private string $root)
    {
    }

    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
    {
        throw new \LogicException('Not used by remote downloading.');
    }

    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
    {
        $this->legacyPutStreamCalled = true;
        $path = $this->absolutePath($objectKey);
        $contents = stream_get_contents($stream);
        if (!is_string($contents)) {
            throw MediaValidationException::withCode('storage_write_failed');
        }
        file_put_contents($path, $contents);
        return new StoredObject($objectKey, strlen($contents), hash('sha256', $contents));
    }

    public function absolutePath(string $objectKey): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $objectKey;
    }

    public function delete(string $objectKey): void
    {
        @unlink($this->absolutePath($objectKey));
    }

    public function createStaging(string $objectKey): PrivateStagingFile
    {
        $path = $this->root . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.download.part';
        $file = new PrivateStagingFile($path, fopen($path, 'x+b'));
        $this->staging[spl_object_id($file)] = $file;
        return $file;
    }

    public function promoteStaging(PrivateStagingFile $staging, string $objectKey, int $maxBytes): StoredObject
    {
        $this->promotions++;
        $staging->finish();
        $contents = file_get_contents($staging->path());
        if (!is_string($contents) || strlen($contents) > $maxBytes) {
            throw MediaValidationException::withCode('media_too_large');
        }
        rename($staging->path(), $this->absolutePath($objectKey));
        unset($this->staging[spl_object_id($staging)]);
        return new StoredObject($objectKey, strlen($contents), hash('sha256', $contents));
    }

    public function discardStaging(PrivateStagingFile $staging): void
    {
        unset($this->staging[spl_object_id($staging)]);
        $staging->close();
        @unlink($staging->path());
    }
}
