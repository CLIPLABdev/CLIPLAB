<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReframePlanTest extends TestCase
{
    public function testManualPlanRequiresItsKeyframeAtZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReframePlan::manual(AspectRatio::fromString('9:16'), new ReframeKeyframe(1, 0.5, 0.5, 'manual'));
    }

    public function testAutomaticPlanRequiresAtLeastTwoKeyframes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReframePlan::automatic(
            AspectRatio::fromString('9:16'),
            ReframePlan::DETECTOR_VERSION,
            [new ReframeKeyframe(0, 0.5, 0.5, 'detected')]
        );
    }

    public function testAutomaticPlanRejectsMoreThanThirtyTwoKeyframes(): void
    {
        $keyframes = [];
        for ($atMs = 0; $atMs <= 32000; $atMs += 1000) {
            $keyframes[] = new ReframeKeyframe($atMs, 0.5, 0.5, 'detected');
        }

        $this->expectException(InvalidArgumentException::class);

        ReframePlan::automatic(AspectRatio::fromString('9:16'), ReframePlan::DETECTOR_VERSION, $keyframes);
    }

    public function testAutomaticPlanRequiresItsFirstKeyframeAtZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReframePlan::automatic(
            AspectRatio::fromString('9:16'),
            ReframePlan::DETECTOR_VERSION,
            [
                new ReframeKeyframe(1, 0.5, 0.5, 'detected'),
                new ReframeKeyframe(2, 0.5, 0.5, 'detected'),
            ]
        );
    }

    /** @dataProvider nonIncreasingKeyframes */
    public function testAutomaticPlanRequiresStrictlyIncreasingKeyframeTimes(array $keyframes): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReframePlan::automatic(AspectRatio::fromString('9:16'), ReframePlan::DETECTOR_VERSION, $keyframes);
    }

    /** @return iterable<string, array{array<int, ReframeKeyframe>}> */
    public function nonIncreasingKeyframes(): iterable
    {
        yield 'duplicate time' => [[
            new ReframeKeyframe(0, 0.5, 0.5, 'detected'),
            new ReframeKeyframe(0, 0.5, 0.5, 'detected'),
        ]];
        yield 'decreasing time' => [[
            new ReframeKeyframe(0, 0.5, 0.5, 'detected'),
            new ReframeKeyframe(2, 0.5, 0.5, 'detected'),
            new ReframeKeyframe(1, 0.5, 0.5, 'detected'),
        ]];
    }
}
