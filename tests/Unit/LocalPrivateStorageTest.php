<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class LocalPrivateStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/private-storage-' . bin2hex(random_bytes(6));
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

    public function testObjectKeyCannotEscapePrivateRoot(): void
    {
        $storage = new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true);

        $this->expectException(MediaValidationException::class);
        $storage->absolutePath('../public/leak.mp4');
    }

    public function testStreamsToAnAtomicConstrainedObjectAndHashesIt(): void
    {
        $storage = new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'safe media bytes');
        rewind($stream);

        $stored = $storage->putStream($stream, 'users/7/4b6a.mp4', 1024);

        self::assertSame(16, $stored->sizeBytes());
        self::assertSame(hash('sha256', 'safe media bytes'), $stored->sha256());
        self::assertSame('safe media bytes', file_get_contents($storage->absolutePath($stored->objectKey())));
        self::assertSame([], glob($this->root . '/users/7/*.part*') ?: []);
    }

    public function testRejectsAnOverLimitStreamWithoutPublishingAPartialFile(): void
    {
        $storage = new LocalPrivateStorage($this->root, 1024, static fn (string $path): bool => true);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_repeat('a', 65));
        rewind($stream);

        try {
            $storage->putStream($stream, 'users/7/too-large.mp4', 64);
            self::fail('Stream above the limit was published.');
        } catch (MediaValidationException $exception) {
            self::assertSame('media_too_large', $exception->publicCode());
        }

        self::assertFileDoesNotExist($this->root . '/users/7/too-large.mp4');
        self::assertSame([], glob($this->root . '/users/7/*.part*') ?: []);
    }

    public function testDeleteRemovesOnlyExactCrashStagingSiblingsForTheObjectKey(): void
    {
        $storage=new LocalPrivateStorage($this->root,1024,static fn (string $path): bool=>true);
        $objectKey='users/7/uploads/'.str_repeat('a',32).'.mp4';
        $path=$storage->absolutePath($objectKey);
        self::assertTrue(mkdir(dirname($path),0700,true));
        file_put_contents($path,'published');
        $first=$path.'.'.str_repeat('b',32).'.download.part';
        $second=$path.'.'.str_repeat('c',32).'.download.part';
        $other=$storage->absolutePath('users/7/uploads/'.str_repeat('d',32).'.mp4')
            .'.'.str_repeat('e',32).'.download.part';
        $nearMiss=$path.'.'.str_repeat('f',31).'.download.part';
        foreach ([$first,$second,$other,$nearMiss] as $candidate) file_put_contents($candidate,'partial');

        $storage->delete($objectKey);

        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($first);
        self::assertFileDoesNotExist($second);
        self::assertFileExists($other);
        self::assertFileExists($nearMiss);
    }
}
