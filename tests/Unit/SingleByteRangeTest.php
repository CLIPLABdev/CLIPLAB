<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\SingleByteRange;
use App\Http\UnsatisfiableByteRange;
use PHPUnit\Framework\TestCase;

final class SingleByteRangeTest extends TestCase
{
    public function testAbsentHeaderSelectsTheWholeRepresentation(): void
    {
        self::assertNull(SingleByteRange::fromHeader(null, 100));
    }

    public function testParsesClosedRangeAndExposesDerivedValues(): void
    {
        $range = SingleByteRange::fromHeader('bytes=10-19', 100);

        self::assertNotNull($range);
        self::assertSame(10, $range->start());
        self::assertSame(19, $range->end());
        self::assertSame(10, $range->length());
        self::assertSame(100, $range->totalBytes());
        self::assertSame('bytes 10-19/100', $range->contentRange());
    }

    public function testParsesOpenEndedAndSuffixRanges(): void
    {
        $openEnded = SingleByteRange::fromHeader('bytes=90-', 100);
        $suffix = SingleByteRange::fromHeader('bytes=-10', 100);
        $oversizedSuffix = SingleByteRange::fromHeader('bytes=-150', 100);

        self::assertNotNull($openEnded);
        self::assertSame([90, 99, 10], [$openEnded->start(), $openEnded->end(), $openEnded->length()]);
        self::assertNotNull($suffix);
        self::assertSame([90, 99, 10], [$suffix->start(), $suffix->end(), $suffix->length()]);
        self::assertNotNull($oversizedSuffix);
        self::assertSame([0, 99, 100], [$oversizedSuffix->start(), $oversizedSuffix->end(), $oversizedSuffix->length()]);
    }

    public function testClampsClosedRangeEndAtEndOfFile(): void
    {
        $range = SingleByteRange::fromHeader('bytes=90-999', 100);

        self::assertNotNull($range);
        self::assertSame([90, 99, 10], [$range->start(), $range->end(), $range->length()]);
    }

    /** @dataProvider invalidRanges */
    public function testRejectsLexicallyInvalidAndUnsatisfiableRanges(?string $header, int $totalBytes): void
    {
        $this->expectException(UnsatisfiableByteRange::class);

        SingleByteRange::fromHeader($header, $totalBytes);
    }

    /** @return iterable<string, array{0:?string,1:int}> */
    public function invalidRanges(): iterable
    {
        yield 'wrong unit' => ['items=0-9', 100];
        yield 'multiple ranges' => ['bytes=0-9,20-29', 100];
        yield 'empty value' => ['bytes=-', 100];
        yield 'empty header' => ['', 100];
        yield 'space before range' => [' bytes=0-9', 100];
        yield 'space after range' => ['bytes=0-9 ', 100];
        yield 'signed start' => ['bytes=+1-2', 100];
        yield 'leading zero start' => ['bytes=00-9', 100];
        yield 'overflowing start' => ['bytes=' . ((string) PHP_INT_MAX) . '0-', PHP_INT_MAX];
        yield 'overflowing end' => ['bytes=0-' . ((string) PHP_INT_MAX) . '0', PHP_INT_MAX];
        yield 'zero suffix' => ['bytes=-0', 100];
        yield 'start at eof' => ['bytes=100-', 100];
        yield 'start beyond eof' => ['bytes=101-', 100];
        yield 'end before start' => ['bytes=20-10', 100];
        yield 'zero total without range' => [null, 0];
        yield 'zero total with range' => ['bytes=0-0', 0];
        yield 'negative total' => [null, -1];
    }
}
