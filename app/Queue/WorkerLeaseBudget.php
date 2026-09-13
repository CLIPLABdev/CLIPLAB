<?php

declare(strict_types=1);

namespace App\Queue;

use InvalidArgumentException;
use OverflowException;

final class WorkerLeaseBudget
{
    public static function requiredSeconds(
        int $geminiHttp,
        int $render,
        int $download,
        int $process,
        int $margin = 30,
        bool $includeCaptions = false,
        int $sourceResolution = 0,
        int $sourceMerge = 0
    ): int {
        if ($geminiHttp <= 0 || $render <= 0 || $download <= 0 || $process <= 0 || $margin < 0 || $sourceResolution < 0 || $sourceMerge < 0) {
            throw new InvalidArgumentException('Worker lease budgets are invalid.');
        }
        if ($download > PHP_INT_MAX - $sourceResolution) {
            throw new OverflowException('Worker lease budget overflow.');
        }
        $download += $sourceResolution;
        if ($download > PHP_INT_MAX - $sourceMerge) {
            throw new OverflowException('Worker lease budget overflow.');
        }
        $download += $sourceMerge;
        if ($download > PHP_INT_MAX - $process) {
            throw new OverflowException('Worker lease budget overflow.');
        }
        if ($includeCaptions && $geminiHttp > PHP_INT_MAX - $process) {
            throw new OverflowException('Worker lease budget overflow.');
        }

        $operation = max($includeCaptions ? $geminiHttp + $process : $geminiHttp, $render, $download + $process);
        if ($operation > PHP_INT_MAX - $margin) {
            throw new OverflowException('Worker lease budget overflow.');
        }

        return $operation + $margin;
    }
}
