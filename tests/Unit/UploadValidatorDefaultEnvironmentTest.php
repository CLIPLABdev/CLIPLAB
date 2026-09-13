<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\UploadValidator;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class UploadValidatorDefaultEnvironmentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/upload-defaults-' . bin2hex(random_bytes(6));
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

    public function testDefaultFinfoAcceptsTheRealMp4Fixture(): void
    {
        $path = $this->writeFixture('fixture.mp4');
        $upload = (new UploadValidator(1024))->validate([
            'name' => 'fixture.mp4',
            'tmp_name' => $path,
            'size' => filesize($path),
            'error' => UPLOAD_ERR_OK,
        ]);

        self::assertSame('mp4', $upload->extension());
        self::assertTrue((new UploadValidator(1024))->mimeAllowedForExtension($upload->mimeType(), $upload->extension()));
    }

    public function testDefaultUploadGuardRejectsALocalFileThatWasNotHttpUploaded(): void
    {
        $path = $this->writeFixture('not-uploaded.mp4');
        $storage = new LocalPrivateStorage($this->directory . '/private', 1024);

        try {
            $storage->putUploaded($path, 'users/1/not-uploaded.mp4');
            self::fail('A local file bypassed is_uploaded_file verification.');
        } catch (MediaValidationException $exception) {
            self::assertSame('invalid_upload', $exception->publicCode());
        }
    }

    private function writeFixture(string $name): string
    {
        $encoded = file_get_contents(dirname(__DIR__) . '/Fixtures/valid-mp4.base64');
        $bytes = is_string($encoded) ? base64_decode(trim($encoded), true) : false;
        self::assertIsString($bytes);
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $bytes);
        return $path;
    }
}
