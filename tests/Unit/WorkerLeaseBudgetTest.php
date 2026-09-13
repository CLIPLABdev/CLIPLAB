<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\WorkerLeaseBudget;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class WorkerLeaseBudgetTest extends TestCase
{
    public function testRequiredSecondsCoversTheLongestSequentialOperationWithMargin(): void
    {
        self::assertSame(270, WorkerLeaseBudget::requiredSeconds(180, 240, 120, 60));
        self::assertSame(250, WorkerLeaseBudget::requiredSeconds(100, 150, 170, 50, 30));
    }

    public function testCaptionBudgetAddsAudioExtractionToTheProviderDeadline(): void
    {
        self::assertSame(390,WorkerLeaseBudget::requiredSeconds(300,240,120,60,30,true));
    }

    public function testYoutubeResolutionIsIncludedBeforeDownloadAndProbe(): void
    {
        self::assertSame(330, WorkerLeaseBudget::requiredSeconds(180, 240, 150, 60, 30, true, 90));
    }

    public function testAdaptiveYoutubeMergeAddsItsDeadlineAfterDownload(): void
    {
        self::assertSame(450, WorkerLeaseBudget::requiredSeconds(180, 240, 150, 60, 30, true, 90, 120));
    }

    public function testAdaptiveYoutubeMergeRejectsOverflow(): void
    {
        $this->expectException(OverflowException::class);
        WorkerLeaseBudget::requiredSeconds(1, 1, 1, 1, 0, false, 0, PHP_INT_MAX);
    }

    public function testZeroMarginKeepsTheExactOperationBudget(): void
    {
        self::assertSame(2, WorkerLeaseBudget::requiredSeconds(1, 1, 1, 1, 0));
        self::assertSame(PHP_INT_MAX, WorkerLeaseBudget::requiredSeconds(PHP_INT_MAX, 1, 1, 1, 0));
    }

    /** @dataProvider invalidBudgets */
    public function testRejectsNonPositiveOperationsAndNegativeMargin(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkerLeaseBudget::requiredSeconds(...$arguments);
    }

    /** @return iterable<string, array{array{int,int,int,int,int}}> */
    public function invalidBudgets(): iterable
    {
        yield 'gemini zero' => [[0, 1, 1, 1, 0]];
        yield 'render negative' => [[1, -1, 1, 1, 0]];
        yield 'download zero' => [[1, 1, 0, 1, 0]];
        yield 'process zero' => [[1, 1, 1, 0, 0]];
        yield 'margin negative' => [[1, 1, 1, 1, -1]];
        yield 'merge negative' => [[1, 1, 1, 1, 0, false, 0, -1]];
    }

    public function testRejectsOverflowInSequentialDownloadAndProcessBudget(): void
    {
        $this->expectException(OverflowException::class);
        WorkerLeaseBudget::requiredSeconds(1, 1, PHP_INT_MAX, 1, 0);
    }

    public function testRejectsOverflowWhenAddingTheSafetyMargin(): void
    {
        $this->expectException(OverflowException::class);
        WorkerLeaseBudget::requiredSeconds(PHP_INT_MAX, 1, 1, 1, 1);
    }
}
