<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\OpusClipTimeRanges;
use PHPUnit\Framework\TestCase;

final class OpusClipTimeRangesTest extends TestCase
{
    /** @dataProvider ranges */
    public function testReadsTheSourceWindowFromCommonShapes(mixed $input, ?array $expected): void
    {
        self::assertSame($expected, OpusClipTimeRanges::sourceWindow($input));
    }

    public static function ranges(): array
    {
        return [
            'pairs' => [[[12.5, 40]], [12.5, 40.0]],
            'objects spanning segments' => [[['start' => 10, 'end' => 20], ['start' => 25, 'end' => 31]], [10.0, 31.0]],
            'timestamps' => [['00:01:05.5-00:01:30'], [65.5, 90.0]],
            'flat pair' => [[12, 30], [12.0, 30.0]],
            'milliseconds' => [[['startMs' => 1000, 'endMs' => 5000]], [1.0, 5.0]],
            'missing' => [null, null],
            'inverted' => [[[30, 10]], null],
            'garbage' => [['abc'], null],
        ];
    }
}
