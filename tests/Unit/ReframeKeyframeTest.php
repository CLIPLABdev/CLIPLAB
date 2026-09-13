<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\Reframe\ReframeKeyframe;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReframeKeyframeTest extends TestCase
{
    public function testItStoresCoordinatesAsExactMillionths(): void
    {
        $point = new ReframeKeyframe(1250, 0.225, 1.0, 'detected');

        self::assertSame(1250, $point->atMs());
        self::assertSame(0.225, $point->centerX());
        self::assertSame(1.0, $point->centerY());
        self::assertSame('0.225000', $point->centerXDecimal());
        self::assertSame('1.000000', $point->centerYDecimal());
        self::assertSame('detected', $point->source());
    }

    /** @dataProvider invalidKeyframes */
    public function testItRejectsInvalidKeyframeInvariants(int $atMs, float $centerX, float $centerY, string $source): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReframeKeyframe($atMs, $centerX, $centerY, $source);
    }

    /** @return iterable<string, array{int, float, float, string}> */
    public function invalidKeyframes(): iterable
    {
        yield 'negative time' => [-1, 0.5, 0.5, 'manual'];
        yield 'negative x' => [0, -0.001, 0.5, 'manual'];
        yield 'x above one' => [0, 1.001, 0.5, 'manual'];
        yield 'not finite x' => [0, INF, 0.5, 'manual'];
        yield 'not finite y' => [0, 0.5, NAN, 'manual'];
        yield 'unknown source' => [0, 0.5, 0.5, 'auto'];
    }
}
