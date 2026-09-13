<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\MediaDuration;
use App\Media\MediaMetadata;
use PHPUnit\Framework\TestCase;

final class MediaDurationTest extends TestCase
{
    public function testDecimalTruncationKeepsBusinessCeilingAndSupportsSubsecondOutput(): void
    {
        self::assertTrue(class_exists(MediaDuration::class), 'Shared precise duration parser is missing.');
        foreach ([
            ['45.011634', 46, 45011], ['45.011', 46, 45011], ['45.999999', 46, 45999],
            ['45', 45, 45000], ['0.967', 1, 967], ['86400.000', 86400, 86400000],
            ['0001.000001', 2, 1000], ['0.000001', 1, 0],
        ] as [$raw, $ceil, $milliseconds]) {
            $duration = MediaDuration::fromFfprobe($raw);
            self::assertSame($ceil, $duration->secondsCeil());
            self::assertSame($milliseconds, $duration->millisecondsFloor());
        }
    }

    public function testRejectsInvalidOrUnboundedDurations(): void
    {
        self::assertTrue(class_exists(MediaDuration::class), 'Shared precise duration parser is missing.');
        foreach ([null, [], true, '-1', '0', '0.000', 'NaN', 'INF', '1e3', ' 1', '86400.0001', str_repeat('9', 1000)] as $raw) {
            try {
                MediaDuration::fromFfprobe($raw);
                self::fail('Invalid duration was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLegacyMetadataIsCompatibleButCannotPretendItHasExactEof(): void
    {
        $legacy = new MediaMetadata(46, 1920, 1080, 'h264', 'aac', true);
        self::assertTrue(method_exists($legacy, 'durationMilliseconds'), 'Optional precise metadata is missing.');
        self::assertNull($legacy->durationMilliseconds());
        $precise = new MediaMetadata(46, 1920, 1080, 'h264', 'aac', true, 45011);
        self::assertSame(46, $precise->durationSeconds());
        self::assertSame(45011, $precise->durationMilliseconds());
        $this->expectException(\InvalidArgumentException::class);
        new MediaMetadata(46, 1920, 1080, 'h264', 'aac', true, 46001);
    }
}
