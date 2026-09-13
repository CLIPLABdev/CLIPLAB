<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Ai\AiClipSuggestion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiClipSuggestionPersistenceTest extends TestCase
{
    public function testRejectsSubEpsilonTimelineThatWouldPersistAsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiClipSuggestion(
            0,
            'Sub-epsilon',
            0.0,
            0.0000000005,
            0.0000000005,
            50,
            'Reason',
            'Hook',
            'other'
        );
    }

    public function testRejectsDistinctTimesThatCollapseToTheSameThousandth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiClipSuggestion(
            0,
            'Collapsed',
            1.0000000001,
            1.0000000009,
            0.001,
            50,
            'Reason',
            'Hook',
            'other'
        );
    }

    public function testCanonicalizesHarmlessFloatingNoiseToStableDatabaseValues(): void
    {
        $clip = new AiClipSuggestion(
            0,
            'Stable',
            10.1250000001,
            40.1250000001,
            30.0000000001,
            50,
            'Reason',
            'Hook',
            'other'
        );

        self::assertSame(10.125, $clip->startTime());
        self::assertSame(40.125, $clip->endTime());
        self::assertSame(30.0, $clip->duration());
    }

    public function testAcceptsDurationDriftAtInclusiveTwoHundredFiftyMillisecondBoundary(): void
    {
        $clip = new AiClipSuggestion(
            0,
            'Inclusive boundary',
            11.758,
            32.008,
            20.000,
            50,
            'Reason',
            'Hook',
            'other'
        );

        self::assertSame(11.758, $clip->startTime());
        self::assertSame(32.008, $clip->endTime());
        self::assertSame(20.0, $clip->duration());
    }

    public function testRejectsDurationDriftAboveTwoHundredFiftyMilliseconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiClipSuggestion(
            0,
            'Outside boundary',
            11.758,
            32.009,
            20.000,
            50,
            'Reason',
            'Hook',
            'other'
        );
    }
}
