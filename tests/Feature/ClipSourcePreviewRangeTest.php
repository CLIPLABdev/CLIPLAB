<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PrivateStorage;
use App\Controllers\ClipSourcePreviewController;
use App\Core\Request;
use App\Core\Response;
use App\Media\StoredObject;
use App\Services\PrivateRangeResponseFactory;
use PHPUnit\Framework\TestCase;

final class ClipSourcePreviewRangeTest extends TestCase
{
    private string $file;
    private string $bytes;

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 14];
        $path = tempnam(sys_get_temp_dir(), 'source-preview-range-');
        self::assertIsString($path);
        $this->file = $path;
        $this->bytes = str_repeat('abcdefghijklmnopqrstuvwxyz', 100000);
        file_put_contents($this->file, $this->bytes);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testControllerForwardsAbsentClosedOpenEndedAndSuffixRanges(): void
    {
        $controller = $this->controller($this->file, strlen($this->bytes));

        $full = $controller->show(Request::fake('GET', '/clips/81/source-preview'), ['id' => '81']);
        self::assertSame(200, $full->status());
        self::assertSame((string) strlen($this->bytes), $full->header('Content-Length'));
        self::assertSame($this->bytes, $this->send($full));

        $closed = $controller->show(Request::fake('GET', '/clips/81/source-preview', [], ['Range' => 'bytes=1048570-1048580']), ['id' => '81']);
        self::assertSame(206, $closed->status());
        self::assertSame('bytes 1048570-1048580/' . strlen($this->bytes), $closed->header('Content-Range'));
        self::assertSame(substr($this->bytes, 1048570, 11), $this->send($closed));

        $openEnded = $controller->show(Request::fake('GET', '/clips/81/source-preview', [], ['Range' => 'bytes=2599990-']), ['id' => '81']);
        self::assertSame(206, $openEnded->status());
        self::assertSame(substr($this->bytes, 2599990), $this->send($openEnded));

        $suffix = $controller->show(Request::fake('GET', '/clips/81/source-preview', [], ['Range' => 'bytes=-7']), ['id' => '81']);
        self::assertSame(206, $suffix->status());
        self::assertSame(substr($this->bytes, -7), $this->send($suffix));
    }

    public function testMalformedAndUnsatisfiableRangeReturns416WithoutPrivateData(): void
    {
        $controller = $this->controller($this->file, strlen($this->bytes));

        foreach (['bytes=0-1,4-5', 'bytes=-0', 'bytes=2600000-'] as $header) {
            $response = $controller->show(
                Request::fake('GET', '/clips/81/source-preview', [], ['Range' => $header]),
                ['id' => '81']
            );

            self::assertSame(416, $response->status());
            self::assertSame('bytes */2600000', $response->header('Content-Range'));
            self::assertSame('0', $response->header('Content-Length'));
            self::assertSame('', $this->send($response));
            self::assertStringNotContainsString($this->file, $response->body());
        }
    }

    public function testMalformedRangeCannotTurnMissingStorageInto416(): void
    {
        $controller = $this->controller($this->file . '.missing', strlen($this->bytes));

        $response = $controller->show(
            Request::fake('GET', '/clips/81/source-preview', [], ['Range' => 'bytes=0-1,4-5']),
            ['id' => '81']
        );

        self::assertSame(404, $response->status());
        self::assertStringNotContainsString($this->file, $response->body());
    }

    private function controller(string $path, int $expectedSize): ClipSourcePreviewController
    {
        return new ClipSourcePreviewController(
            static fn (int $clipId, int $userId): array => [
                'storage_disk' => 'local',
                'object_key' => 'sources/preview.webm',
                'size_bytes' => $expectedSize,
                'mime_type' => 'video/webm',
            ],
            $this->storage($path),
            new PrivateRangeResponseFactory()
        );
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

    private function send(Response $response): string
    {
        ob_start();
        try {
            $response->send();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
