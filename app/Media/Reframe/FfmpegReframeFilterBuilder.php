<?php

declare(strict_types=1);

namespace App\Media\Reframe;

use InvalidArgumentException;

final class FfmpegReframeFilterBuilder
{
    private const MAX_FILTER_BYTES = 24576;

    public function build(ReframePlan $plan, int $sourceWidth, int $sourceHeight): string
    {
        $ratio = $plan->aspectRatio();
        if ($ratio->isOriginal() || $sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new InvalidArgumentException('Reframe filter geometry is invalid.');
        }

        $targetWidth = $ratio->outputWidth();
        $targetHeight = $ratio->outputHeight();
        if ($targetWidth === null || $targetHeight === null) {
            throw new InvalidArgumentException('Reframe filter geometry is invalid.');
        }

        if ($sourceWidth * $targetHeight > $sourceHeight * $targetWidth) {
            $cropHeight = $sourceHeight;
            $candidate = intdiv($sourceHeight * $targetWidth, $targetHeight);
            $cropWidth = 2 * intdiv($candidate, 2);
        } else {
            $cropWidth = $sourceWidth;
            $candidate = intdiv($sourceWidth * $targetHeight, $targetWidth);
            $cropHeight = 2 * intdiv($candidate, 2);
        }

        if ($cropWidth < 2 || $cropHeight < 2) {
            throw new InvalidArgumentException('Reframe crop geometry is too small.');
        }

        [$x, $y] = $this->positionExpressions($plan, $sourceWidth, $sourceHeight, $cropWidth, $cropHeight);
        $filter = sprintf(
            'setpts=PTS-STARTPTS,crop=%d:%d:%s:%s,scale=%d:%d:flags=lanczos,setsar=1',
            $cropWidth,
            $cropHeight,
            $x,
            $y,
            $targetWidth,
            $targetHeight
        );

        if (strlen($filter) >= self::MAX_FILTER_BYTES || strpbrk($filter, ";[]\r\n") !== false) {
            throw new InvalidArgumentException('Reframe filter is invalid.');
        }

        return $filter;
    }

    /** @return array{string, string} */
    private function positionExpressions(
        ReframePlan $plan,
        int $sourceWidth,
        int $sourceHeight,
        int $cropWidth,
        int $cropHeight
    ): array {
        if ($plan->mode() === 'center') {
            [$x, $y] = $this->position(0.5, 0.5, $sourceWidth, $sourceHeight, $cropWidth, $cropHeight);

            return [$this->decimal($x), $this->decimal($y)];
        }

        $keyframes = $plan->keyframes();
        if ($plan->mode() === 'manual' && count($keyframes) === 1) {
            [$x, $y] = $this->position(
                $keyframes[0]->centerX(),
                $keyframes[0]->centerY(),
                $sourceWidth,
                $sourceHeight,
                $cropWidth,
                $cropHeight
            );

            return [$this->decimal($x), $this->decimal($y)];
        }

        if ($plan->mode() !== 'auto' || count($keyframes) < 2) {
            throw new InvalidArgumentException('Reframe filter plan is invalid.');
        }

        $times = [];
        $xValues = [];
        $yValues = [];
        foreach ($keyframes as $keyframe) {
            $times[] = $keyframe->atMs() / 1000;
            [$xValues[], $yValues[]] = $this->position(
                $keyframe->centerX(),
                $keyframe->centerY(),
                $sourceWidth,
                $sourceHeight,
                $cropWidth,
                $cropHeight
            );
        }

        return [
            $this->piecewiseExpression($times, $xValues),
            $this->piecewiseExpression($times, $yValues),
        ];
    }

    /** @return array{float, float} */
    private function position(
        float $centerX,
        float $centerY,
        int $sourceWidth,
        int $sourceHeight,
        int $cropWidth,
        int $cropHeight
    ): array {
        $x = max(0.0, min($sourceWidth - $cropWidth, $centerX * $sourceWidth - $cropWidth / 2));
        $y = max(0.0, min($sourceHeight - $cropHeight, $centerY * $sourceHeight - $cropHeight / 2));

        return [$x, $y];
    }

    /** @param list<float> $times @param list<float> $values */
    private function piecewiseExpression(array $times, array $values): string
    {
        $expression = $this->decimal($values[count($values) - 1]);
        for ($index = count($values) - 2; $index >= 0; $index--) {
            $startTime = $this->decimal($times[$index]);
            $endTime = $this->decimal($times[$index + 1]);
            $startValue = $this->decimal($values[$index]);
            $endValue = $this->decimal($values[$index + 1]);
            $linear = sprintf(
                '%s+(%s-%s)*((t-%s)/(%s-%s))',
                $startValue,
                $endValue,
                $startValue,
                $startTime,
                $endTime,
                $startTime
            );
            $expression = sprintf('if(lt(t\,%s)\,%s\,%s)', $endTime, $linear, $expression);
        }

        return $expression;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}
