<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DownloadBoundaryTest extends TestCase
{
    public function testDownloaderExposesDedicatedTransportAndPrivateStagingBoundaries(): void
    {
        self::assertTrue(interface_exists(\App\Media\DownloadTransport::class));
        self::assertTrue(class_exists(\App\Media\DownloadRequest::class));
        self::assertTrue(class_exists(\App\Media\DownloadResponse::class));
        self::assertTrue(interface_exists(\App\Storage\PrivateStagingArea::class));
        self::assertTrue(class_exists(\App\Storage\PrivateStagingFile::class));
    }
}
