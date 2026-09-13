<?php
declare(strict_types=1);
namespace App\Media;

final class LogoDimensions
{
    /**
     * Keep requested width percentage and cap height at 25% of the canvas.
     * Explicit integer dimensions prevent extreme PNG ratios expanding unbounded
     * in FFmpeg. Aspect is preserved to the nearest available whole pixel.
     *
     * @return array{int,int}
     */
    public static function fit(int $imageWidth,int $imageHeight,int $canvasWidth,int $canvasHeight,int $widthPercent): array
    {
        if ($imageWidth<1 || $imageHeight<1 || $imageWidth>2048 || $imageHeight>2048
            || $canvasWidth<2 || $canvasHeight<2 || $canvasWidth>8192 || $canvasHeight>8192
            || $widthPercent<5 || $widthPercent>25) {
            throw new \InvalidArgumentException('Logo dimensions are invalid.');
        }
        $maxWidth=max(1,(int)floor($canvasWidth*$widthPercent/100));
        $maxHeight=max(1,(int)floor($canvasHeight/4));
        $scale=min($maxWidth/$imageWidth,$maxHeight/$imageHeight);
        return [max(1,min($maxWidth,(int)round($imageWidth*$scale))),max(1,min($maxHeight,(int)round($imageHeight*$scale)))];
    }
}
