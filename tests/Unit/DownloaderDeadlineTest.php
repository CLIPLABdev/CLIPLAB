<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use App\Media\PinnedHttpDownloader;
use App\Media\UploadValidator;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class DownloaderDeadlineTest extends TestCase
{
    public function testRedirectsShareOneMonotonicDeadline(): void
    {
        $calls = 0;
        $root = sys_get_temp_dir() . '/downloader-deadline-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $validator = new DirectUrlValidator(static fn (string $host): array => ['1.1.1.1']);
        $storage = new LocalPrivateStorage($root, 1024, static fn (string $path): bool => true);
        $downloader = new PinnedHttpDownloader(
            $validator,
            new UploadValidator(1024, static fn (string $path): string => 'video/mp4'),
            1,
            1,
            $storage,
            function ($url, int $timeout) use (&$calls): array {
                $calls++;
                usleep(1100000);
                return ['status' => 302, 'headers' => ['location' => '/next.mp4'], 'stream' => null];
            }
        );
        try {
            $downloader->download($validator->validate('https://cdn.example.test/first.mp4'), $storage, 'x.mp4', 1024);
            self::fail('The second redirect received a fresh timeout budget.');
        } catch (MediaValidationException $exception) {
            self::assertSame('remote_timeout', $exception->publicCode());
        } finally {
            @rmdir($root);
        }
        self::assertSame(1, $calls);
    }
}
