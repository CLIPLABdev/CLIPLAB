<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PrivateStorage;
use App\Controllers\ClipAssetController;
use App\Core\Request;
use App\Media\StoredObject;
use App\Services\PrivateFileResponseFactory;
use PHPUnit\Framework\TestCase;

final class ClipAssetAccessTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
        $file = tempnam(sys_get_temp_dir(), 'clip-asset-');
        self::assertIsString($file);
        $this->file = $file;
        file_put_contents($this->file, 'private-video-bytes');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testOwnerCanStreamCompletedVideoAndThumbnailThroughTheFactory(): void
    {
        $kinds = [];
        $controller = new ClipAssetController(
            static function (int $clipId, int $userId, string $kind) use (&$kinds): ?array {
                $kinds[] = [$clipId, $userId, $kind];

                return [
                    'object_key' => $kind === 'video' ? 'processed/31/71.mp4' : 'thumbnails/31/71.jpg',
                    'size_bytes' => strlen('private-video-bytes'),
                    'mime_type' => $kind === 'video' ? 'video/mp4' : 'image/jpeg',
                ];
            },
            $this->storage($this->file),
            new PrivateFileResponseFactory()
        );

        $download = $controller->download(Request::fake('GET', '/clips/71/download'), ['id' => '71']);
        self::assertSame(200, $download->status());
        self::assertSame('video/mp4', $download->header('Content-Type'));
        self::assertSame('attachment; filename="clip-71.mp4"', $download->header('Content-Disposition'));
        self::assertSame('private, no-store', $download->header('Cache-Control'));
        self::assertSame('', $download->body());
        ob_start();
        $download->send();
        self::assertSame('private-video-bytes', (string) ob_get_clean());

        $thumbnail = $controller->thumbnail(Request::fake('GET', '/clips/71/thumbnail'), ['id' => '71']);
        self::assertSame('image/jpeg', $thumbnail->header('Content-Type'));
        self::assertSame([[71, 7, 'video'], [71, 7, 'thumbnail']], $kinds);
    }

    public function testForeignMissingMalformedIncompleteAndMissingFilesShareOne404(): void
    {
        $privateKey = 'processed/31/secret-video.mp4';
        $controller = new ClipAssetController(
            static fn (): ?array => null,
            $this->storage($this->file),
            new PrivateFileResponseFactory()
        );
        $foreign = $controller->download(Request::fake('GET', '/clips/71/download'), ['id' => '71']);
        $missing = $controller->download(Request::fake('GET', '/clips/999999999/download'), ['id' => '999999999']);
        $malformed = $controller->download(Request::fake('GET', '/clips/01/download'), ['id' => '01']);

        $missingFile = new ClipAssetController(
            static fn (): array => ['object_key' => $privateKey, 'size_bytes' => 99, 'mime_type' => 'video/mp4'],
            $this->storage($this->file . '.missing'),
            new PrivateFileResponseFactory()
        );
        $unavailable = $missingFile->download(Request::fake('GET', '/clips/71/download'), ['id' => '71']);

        foreach ([$foreign, $missing, $malformed, $unavailable] as $response) {
            self::assertSame(404, $response->status());
            self::assertSame($foreign->body(), $response->body());
            self::assertStringNotContainsString($privateKey, $response->body());
            self::assertStringNotContainsString($this->file, $response->body());
        }
    }

    public function testGuestIsRedirectedBeforeArtifactLookup(): void
    {
        $lookups = 0;
        $controller = new ClipAssetController(
            static function () use (&$lookups): ?array {
                ++$lookups;
                return null;
            },
            $this->storage($this->file),
            new PrivateFileResponseFactory()
        );
        $_SESSION = [];

        $response = $controller->download(Request::fake('GET', '/clips/71/download'), ['id' => '71']);

        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $lookups);
    }

    private function storage(string $path): PrivateStorage
    {
        return new class ($path) implements PrivateStorage {
            public function __construct(private string $path)
            {
            }

            public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
            {
                throw new \LogicException('Not used.');
            }

            public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
            {
                throw new \LogicException('Not used.');
            }

            public function absolutePath(string $objectKey): string
            {
                return $this->path;
            }

            public function delete(string $objectKey): void
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
