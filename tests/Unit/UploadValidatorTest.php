<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\UploadValidator;
use PHPUnit\Framework\TestCase;

final class UploadValidatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/upload-validator-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testRejectsMp4ExtensionWithHtmlMimeAndSignature(): void
    {
        $path = $this->write('attack.mp4', '<html>not video</html>');
        $validator = new UploadValidator(1024, static fn (string $path): string => 'text/html');

        try {
            $validator->validate($this->file('attack.mp4', $path));
            self::fail('An HTML document must not be accepted as MP4.');
        } catch (MediaValidationException $exception) {
            self::assertSame('invalid_media_container', $exception->publicCode());
        }
    }

    public function testAcceptsOnlyAnAllowedExtensionMimeAndContainerTogether(): void
    {
        $path = $this->write('episode.mp4', $this->mp4());
        $validator = new UploadValidator(1024, static fn (string $path): string => 'video/mp4');

        $upload = $validator->validate($this->file('episode.mp4', $path));

        self::assertSame('mp4', $upload->extension());
        self::assertSame('video/mp4', $upload->mimeType());
        self::assertSame(filesize($path), $upload->sizeBytes());
    }

    public function testRejectsFilesWhoseActualSizeExceedsTheConfiguredLimit(): void
    {
        $path = $this->write('large.mp4', $this->mp4() . str_repeat('x', 100));
        $validator = new UploadValidator(24, static fn (string $path): string => 'video/mp4');

        $this->expectException(MediaValidationException::class);
        $this->expectExceptionCode(0);
        try {
            $validator->validate($this->file('large.mp4', $path));
        } catch (MediaValidationException $exception) {
            self::assertSame('upload_too_large', $exception->publicCode());
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function file(string $name, string $path): array
    {
        return ['name' => $name, 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK, 'type' => 'video/mp4'];
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2avc1mp41";
    }
}
