<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use App\Services\PrivateRangeResponseFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrivateRangeResponseFactoryTest extends TestCase
{
    private string $fixturePath;
    private string $fixtureBytes;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'private-range-response-');
        self::assertIsString($path);
        $this->fixturePath = $path;
        $this->fixtureBytes = str_repeat('0123456789abcdef', 131073);
        file_put_contents($this->fixturePath, $this->fixtureBytes);
    }

    protected function tearDown(): void
    {
        foreach ([$this->fixturePath ?? '', ($this->fixturePath ?? '') . '.moved'] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testStreamsTheWholeFileWithPrivatePreviewHeaders(): void
    {
        $response = (new PrivateRangeResponseFactory())->preview(
            $this->fixturePath,
            strlen($this->fixtureBytes),
            'video/mp4',
            null
        );

        self::assertSame(200, $response->status());
        self::assertSame('video/mp4', $response->header('Content-Type'));
        self::assertSame((string) strlen($this->fixtureBytes), $response->header('Content-Length'));
        self::assertNull($response->header('Content-Range'));
        $this->assertCommonHeaders($response);
        self::assertSame($this->fixtureBytes, $this->send($response));
    }

    public function testSeeksAndStreamsOnlyTheRequestedBytesAcrossAChunkBoundary(): void
    {
        $response = (new PrivateRangeResponseFactory())->preview(
            $this->fixturePath,
            strlen($this->fixtureBytes),
            'video/mp4',
            'bytes=1048570-1048580'
        );

        self::assertSame(206, $response->status());
        self::assertSame('bytes 1048570-1048580/' . strlen($this->fixtureBytes), $response->header('Content-Range'));
        self::assertSame('11', $response->header('Content-Length'));
        $this->assertCommonHeaders($response);
        self::assertSame(substr($this->fixtureBytes, 1048570, 11), $this->send($response));
    }

    /** @dataProvider supportedMimeTypes */
    public function testNormalizesOnlySupportedVideoMimeTypes(string $input, string $expected): void
    {
        $response = (new PrivateRangeResponseFactory())->preview(
            $this->fixturePath,
            strlen($this->fixtureBytes),
            $input,
            'bytes=0-0'
        );

        self::assertSame($expected, $response->header('Content-Type'));
        self::assertSame(substr($this->fixtureBytes, 0, 1), $this->send($response));
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public function supportedMimeTypes(): iterable
    {
        yield 'mp4' => ['video/mp4', 'video/mp4'];
        yield 'application mp4' => ['application/mp4', 'video/mp4'];
        yield 'quicktime' => ['video/quicktime', 'video/quicktime'];
        yield 'webm' => ['video/webm', 'video/webm'];
    }

    public function testInvalidRangeReturnsAnEmpty416AndClosesThePreflightHandle(): void
    {
        $opened = null;
        $factory = new PrivateRangeResponseFactory(static function (string $path) use (&$opened) {
            $opened = fopen($path, 'rb');

            return $opened;
        });

        $response = $factory->preview(
            $this->fixturePath,
            strlen($this->fixtureBytes),
            'video/mp4',
            'bytes=999999999999999999999999-'
        );

        self::assertSame(416, $response->status());
        self::assertSame('bytes */' . strlen($this->fixtureBytes), $response->header('Content-Range'));
        self::assertSame('0', $response->header('Content-Length'));
        self::assertSame('video/mp4', $response->header('Content-Type'));
        $this->assertCommonHeaders($response);
        self::assertSame('', $this->send($response));
        self::assertFalse(is_resource($opened));
    }

    /** @dataProvider unsupportedMimeTypes */
    public function testRejectsUnsupportedMimeTypesWithoutLeakingThePath(string $mime): void
    {
        try {
            (new PrivateRangeResponseFactory())->preview(
                $this->fixturePath,
                strlen($this->fixtureBytes),
                $mime,
                null
            );
            self::fail('Expected unsupported MIME to fail during preflight.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString($this->fixturePath, $exception->getMessage());
            if ($mime !== '') {
                self::assertStringNotContainsString($mime, $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{0:string}> */
    public function unsupportedMimeTypes(): iterable
    {
        yield 'image' => ['image/jpeg'];
        yield 'parameters' => ['video/mp4; charset=binary'];
        yield 'case variation' => ['Video/MP4'];
        yield 'empty' => [''];
    }

    public function testRejectsBadPathsSizesAndOpenFailuresWithoutLeakingDetails(): void
    {
        $cases = [
            ['relative/private.mp4', strlen($this->fixtureBytes), new PrivateRangeResponseFactory()],
            [$this->fixturePath . '.missing', strlen($this->fixtureBytes), new PrivateRangeResponseFactory()],
            [$this->fixturePath, strlen($this->fixtureBytes) + 1, new PrivateRangeResponseFactory()],
            [$this->fixturePath, 0, new PrivateRangeResponseFactory()],
            [$this->fixturePath, strlen($this->fixtureBytes), new PrivateRangeResponseFactory(static fn () => false)],
            [$this->fixturePath, strlen($this->fixtureBytes), new PrivateRangeResponseFactory(static function () {
                throw new RuntimeException('secret opener diagnostic');
            })],
        ];

        foreach ($cases as [$path, $size, $factory]) {
            try {
                $factory->preview($path, $size, 'video/mp4', null);
                self::fail('Expected invalid private preview preflight to fail.');
            } catch (RuntimeException $exception) {
                self::assertStringNotContainsString($this->fixturePath, $exception->getMessage());
                self::assertStringNotContainsString('secret opener diagnostic', $exception->getMessage());
            }
        }
    }

    public function testEmitterReusesTheSingleHandleReturnedByTheOpener(): void
    {
        $opens = 0;
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $this->fixtureBytes);
        rewind($stream);
        $factory = new PrivateRangeResponseFactory(static function () use (&$opens, $stream) {
            ++$opens;

            return $stream;
        });

        $response = $factory->preview($this->fixturePath, strlen($this->fixtureBytes), 'video/mp4', 'bytes=5-20');
        file_put_contents($this->fixturePath, str_repeat('x', strlen($this->fixtureBytes)));

        self::assertSame(1, $opens);
        self::assertSame(substr($this->fixtureBytes, 5, 16), $this->send($response));
        self::assertSame(1, $opens);
        self::assertFalse(is_resource($stream));
    }

    public function testOpenFilePathRenameRaceNeverReopensAndStillStreamsOriginalBytes(): void
    {
        $opens = 0;
        $factory = new PrivateRangeResponseFactory(static function (string $path) use (&$opens) {
            ++$opens;

            return fopen($path, 'rb');
        });
        $response = $factory->preview($this->fixturePath, strlen($this->fixtureBytes), 'video/mp4', 'bytes=31-62');
        $movedPath = $this->fixturePath . '.moved';
        $renamed = @rename($this->fixturePath, $movedPath);
        if ($renamed) {
            file_put_contents($this->fixturePath, str_repeat('z', strlen($this->fixtureBytes)));
        }

        self::assertSame(substr($this->fixtureBytes, 31, 32), $this->send($response));
        self::assertSame(1, $opens);
    }

    public function testTruncationAfterPreflightProducesSilentShortBodyAndClosesHandle(): void
    {
        $opened = null;
        $factory = new PrivateRangeResponseFactory(static function (string $path) use (&$opened) {
            $opened = fopen($path, 'r+b');

            return $opened;
        });
        $response = $factory->preview(
            $this->fixturePath,
            strlen($this->fixtureBytes),
            'video/mp4',
            'bytes=1048570-1048580'
        );
        self::assertIsResource($opened);
        self::assertTrue(ftruncate($opened, 1048575));

        $body = $this->send($response);

        self::assertSame(206, $response->status());
        self::assertSame('11', $response->header('Content-Length'));
        self::assertSame(substr($this->fixtureBytes, 1048570, 5), $body);
        self::assertStringNotContainsString($this->fixturePath, $body);
        self::assertStringNotContainsString('warning', strtolower($body));
        self::assertFalse(is_resource($opened));
    }

    private function assertCommonHeaders(Response $response): void
    {
        self::assertSame('bytes', $response->header('Accept-Ranges'));
        self::assertSame('private, no-store', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('inline; filename="source-preview"', $response->header('Content-Disposition'));
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
