<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\Reframe\AspectRatio;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AspectRatioTest extends TestCase
{
    /** @dataProvider supportedRatios */
    public function testItMapsOnlyServerOwnedAspectRatios(string $value, ?int $width, ?int $height): void
    {
        $ratio = AspectRatio::fromString($value);

        self::assertSame($value, $ratio->value());
        self::assertSame($width, $ratio->outputWidth());
        self::assertSame($height, $ratio->outputHeight());
        self::assertSame($value === 'original', $ratio->isOriginal());
    }

    public function testOriginalFactoryReturnsTheOriginalRatio(): void
    {
        $ratio = AspectRatio::original();

        self::assertSame('original', $ratio->value());
        self::assertTrue($ratio->isOriginal());
        self::assertNull($ratio->outputWidth());
        self::assertNull($ratio->outputHeight());
    }

    /** @dataProvider unsupportedRatios */
    public function testItRejectsAliasesCaseAndWhitespace(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        AspectRatio::fromString($value);
    }

    /** @return iterable<string, array{string, ?int, ?int}> */
    public function supportedRatios(): iterable
    {
        yield 'original' => ['original', null, null];
        yield 'vertical' => ['9:16', 1080, 1920];
        yield 'square' => ['1:1', 1080, 1080];
        yield 'landscape' => ['16:9', 1920, 1080];
        yield 'portrait' => ['4:5', 1080, 1350];
    }

    /** @return iterable<string, array{string}> */
    public function unsupportedRatios(): iterable
    {
        yield 'uppercase original' => ['ORIGINAL'];
        yield 'trailing whitespace' => ['9:16 '];
        yield 'leading whitespace' => [' 9:16'];
        yield 'trailing tab' => ["9:16\t"];
        yield 'slash alias' => ['9/16'];
        yield 'leading zero alias' => ['09:16'];
    }
}
