<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use App\Services\PrivateFileResponseFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrivateFileResponseFactoryTest extends TestCase
{
    private string $fixturePath;

    private string $fixtureBytes;

    protected function setUp(): void
    {
        $this->fixturePath = sys_get_temp_dir() . '/private-file-response-' . bin2hex(random_bytes(8)) . '.bin';
        $this->fixtureBytes = str_repeat('0123456789abcdef', 163840);
        file_put_contents($this->fixturePath, $this->fixtureBytes);
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixturePath)) {
            unlink($this->fixturePath);
        }
    }

    public function testDownloadStreamsExactBytesWithPrivateSafeHeaders(): void
    {
        $response = (new PrivateFileResponseFactory())->download(
            $this->fixturePath,
            "corte-\r\nmalicioso.mp4",
            strlen($this->fixtureBytes)
        );

        self::assertSame('video/mp4', $response->header('Content-Type'));
        self::assertSame((string) strlen($this->fixtureBytes), $response->header('Content-Length'));
        self::assertSame('private, no-store', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment; filename="', (string) $response->header('Content-Disposition'));
        self::assertStringNotContainsString("\r", (string) $response->header('Content-Disposition'));
        self::assertStringNotContainsString("\n", (string) $response->header('Content-Disposition'));
        self::assertSame($this->fixtureBytes, $this->send($response));
    }

    public function testThumbnailStreamsExactBytesWithPrivateSafeHeaders(): void
    {
        $response = (new PrivateFileResponseFactory())->thumbnail($this->fixturePath, strlen($this->fixtureBytes));

        self::assertSame('image/jpeg', $response->header('Content-Type'));
        self::assertSame((string) strlen($this->fixtureBytes), $response->header('Content-Length'));
        self::assertSame('private, no-store', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame($this->fixtureBytes, $this->send($response));
    }

    public function testRejectsMissingAndMismatchedFilesWithAGenericError(): void
    {
        $factory = new PrivateFileResponseFactory();

        try {
            $factory->download($this->fixturePath . '.missing', 'clip.mp4', 1);
            self::fail('Expected a RuntimeException for a missing file.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to prepare private file response.', $exception->getMessage());
            self::assertStringNotContainsString($this->fixturePath, $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to prepare private file response.');

        $factory->thumbnail($this->fixturePath, strlen($this->fixtureBytes) + 1);
    }

    private function send(Response $response): string
    {
        ob_start();
        $response->send();

        return (string) ob_get_clean();
    }
}
