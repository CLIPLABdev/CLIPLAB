<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Media\DirectUrlValidator;
use App\Media\DownloadRequest;
use App\Media\DownloadResponse;
use App\Media\DownloadTransport;
use App\Media\PinnedHttpDownloader;
use App\Media\StoredObject;
use App\Media\UploadValidator;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class PinnedHttpDownloaderStagingDependencyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pinned-staging-dependency-' . bin2hex(random_bytes(6));
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

    public function testUsesExplicitPrivateStagingWithASeparateStorageImplementation(): void
    {
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        $staging = new LocalPrivateStorage($this->root . '/staging', 1024, static fn (string $path): bool => true);
        $storage = new StreamOnlyPrivateStorage($this->root . '/published');
        $transport = new SingleResponseTransport($this->mp4());

        try {
            $downloader = new PinnedHttpDownloader(
                $validator,
                new UploadValidator(1024, static fn (string $path): string => 'video/mp4'),
                10,
                0,
                $staging,
                $transport
            );
        } catch (\InvalidArgumentException $exception) {
            self::fail('Private staging must be an explicit dependency, not inferred from PrivateStorage at runtime.');
        }
        $stored = $downloader->download($validator->validate('https://cdn.example.test/video.mp4'), $storage, 'users/1/video.mp4', 1024);

        self::assertSame('users/1/video.mp4', $stored->objectKey());
        self::assertSame(1, $storage->putStreamCalls);
        self::assertFileExists($storage->absolutePath($stored->objectKey()));
        self::assertSame([], glob($this->root . '/staging/users/1/*.download.part') ?: []);
    }

    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41";
    }
}

final class SingleResponseTransport implements DownloadTransport
{
    public function __construct(private string $body)
    {
    }

    /** @param callable(string): void $onChunk */
    public function download(DownloadRequest $request, callable $onChunk): DownloadResponse
    {
        $onChunk($this->body);
        return new DownloadResponse(200, ['content-type' => 'video/mp4']);
    }
}

final class StreamOnlyPrivateStorage implements PrivateStorage
{
    public int $putStreamCalls = 0;

    public function __construct(private string $root)
    {
        mkdir($root, 0700, true);
    }

    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
    {
        throw new \LogicException('Remote downloading does not call putUploaded.');
    }

    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
    {
        $this->putStreamCalls++;
        $contents = stream_get_contents($stream);
        if (!is_string($contents) || strlen($contents) > $maxBytes) {
            throw new \RuntimeException('Invalid staged content.');
        }
        $path = $this->absolutePath($objectKey);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
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
}
