<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\FfmpegReframeFilterBuilder;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FfmpegReframeFilterBuilderTest extends TestCase
{
    public function testBuildsTheLiteralCenteredPortraitFilterForLandscapeFullHd(): void
    {
        self::assertSame(
            'setpts=PTS-STARTPTS,crop=606:1080:657.000000:0.000000,scale=1080:1920:flags=lanczos,setsar=1',
            $this->buildCenter('9:16', 1920, 1080)
        );
    }

    /** @dataProvider centeredGeometry */
    public function testBuildsDeterministicCenteredGeometryForLandscapeAndPortraitSources(
        int $sourceWidth,
        int $sourceHeight,
        string $ratio,
        string $expected
    ): void {
        self::assertSame($expected, $this->buildCenter($ratio, $sourceWidth, $sourceHeight));
    }

    /** @return iterable<string, array{int, int, string, string}> */
    public function centeredGeometry(): iterable
    {
        yield 'landscape 1:1' => [1920, 1080, '1:1', 'setpts=PTS-STARTPTS,crop=1080:1080:420.000000:0.000000,scale=1080:1080:flags=lanczos,setsar=1'];
        yield 'landscape 16:9' => [1920, 1080, '16:9', 'setpts=PTS-STARTPTS,crop=1920:1080:0.000000:0.000000,scale=1920:1080:flags=lanczos,setsar=1'];
        yield 'landscape 4:5' => [1920, 1080, '4:5', 'setpts=PTS-STARTPTS,crop=864:1080:528.000000:0.000000,scale=1080:1350:flags=lanczos,setsar=1'];
        yield 'portrait 9:16' => [1080, 1920, '9:16', 'setpts=PTS-STARTPTS,crop=1080:1920:0.000000:0.000000,scale=1080:1920:flags=lanczos,setsar=1'];
        yield 'portrait 1:1' => [1080, 1920, '1:1', 'setpts=PTS-STARTPTS,crop=1080:1080:0.000000:420.000000,scale=1080:1080:flags=lanczos,setsar=1'];
        yield 'portrait 16:9' => [1080, 1920, '16:9', 'setpts=PTS-STARTPTS,crop=1080:606:0.000000:657.000000,scale=1920:1080:flags=lanczos,setsar=1'];
        yield 'portrait 4:5' => [1080, 1920, '4:5', 'setpts=PTS-STARTPTS,crop=1080:1350:0.000000:285.000000,scale=1080:1350:flags=lanczos,setsar=1'];
    }

    /** @dataProvider manualBoundaryGeometry */
    public function testClampsManualFocusAtAllFourBoundaries(
        int $sourceWidth,
        int $sourceHeight,
        float $centerX,
        float $centerY,
        string $expectedCrop
    ): void {
        $plan = ReframePlan::manual(
            AspectRatio::fromString('1:1'),
            new ReframeKeyframe(0, $centerX, $centerY, 'manual')
        );

        $filter = (new FfmpegReframeFilterBuilder())->build($plan, $sourceWidth, $sourceHeight);

        self::assertStringContainsString($expectedCrop, $filter);
    }

    /** @return iterable<string, array{int, int, float, float, string}> */
    public function manualBoundaryGeometry(): iterable
    {
        yield 'landscape top left' => [1600, 1200, 0.0, 0.0, 'crop=1200:1200:0.000000:0.000000'];
        yield 'landscape bottom right' => [1600, 1200, 1.0, 1.0, 'crop=1200:1200:400.000000:0.000000'];
        yield 'portrait top left' => [1200, 1600, 0.0, 0.0, 'crop=1200:1200:0.000000:0.000000'];
        yield 'portrait bottom right' => [1200, 1600, 1.0, 1.0, 'crop=1200:1200:0.000000:400.000000'];
    }

    public function testForcesTheDerivedCropDimensionDownToAnEvenInteger(): void
    {
        self::assertStringContainsString('crop=606:1080:', $this->buildCenter('9:16', 1919, 1080));
        self::assertStringContainsString('crop=1080:606:', $this->buildCenter('16:9', 1080, 1919));
    }

    public function testBuildsPiecewiseAutomaticExpressionsWithEscapedCommasAndPreservedEndpoints(): void
    {
        $plan = ReframePlan::automatic(AspectRatio::fromString('9:16'), ReframePlan::DETECTOR_VERSION, [
            new ReframeKeyframe(0, 0.0, 0.5, 'detected'),
            new ReframeKeyframe(1000, 0.5, 0.5, 'detected'),
            new ReframeKeyframe(2000, 1.0, 0.5, 'detected'),
        ]);

        $filter = (new FfmpegReframeFilterBuilder())->build($plan, 1920, 1080);

        self::assertStringContainsString(
            'if(lt(t\,1.000000)\,0.000000+(657.000000-0.000000)*((t-0.000000)/(1.000000-0.000000))\,if(lt(t\,2.000000)\,657.000000+(1314.000000-657.000000)*((t-1.000000)/(2.000000-1.000000))\,1314.000000))',
            $filter
        );
        self::assertStringNotContainsString(';', $filter);
        self::assertStringNotContainsString('[', $filter);
        self::assertStringNotContainsString(']', $filter);
        self::assertStringNotContainsString("\r", $filter);
        self::assertStringNotContainsString("\n", $filter);
    }

    public function testKeepsTheMaximumAutomaticPlanBelowTheArgvSafetyLimit(): void
    {
        $keyframes = [];
        for ($index = 0; $index < 32; $index++) {
            $keyframes[] = new ReframeKeyframe($index * 1000, $index / 31, 0.5, 'detected');
        }
        $plan = ReframePlan::automatic(AspectRatio::fromString('4:5'), ReframePlan::DETECTOR_VERSION, $keyframes);

        $filter = (new FfmpegReframeFilterBuilder())->build($plan, 1920, 1080);

        self::assertLessThan(24576, strlen($filter));
        self::assertStringContainsString('0.000000', $filter);
        self::assertStringContainsString('1056.000000', $filter);
    }

    /** @dataProvider invalidBuilds */
    public function testRejectsOriginalInvalidGeometryAndSubTwoPixelCrops(ReframePlan $plan, int $width, int $height): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FfmpegReframeFilterBuilder())->build($plan, $width, $height);
    }

    /** @return iterable<string, array{ReframePlan, int, int}> */
    public function invalidBuilds(): iterable
    {
        $center = ReframePlan::center(AspectRatio::fromString('9:16'));

        yield 'original plan' => [ReframePlan::original(), 1920, 1080];
        yield 'zero width' => [$center, 0, 1080];
        yield 'negative width' => [$center, -1, 1080];
        yield 'zero height' => [$center, 1920, 0];
        yield 'negative height' => [$center, 1920, -1];
        yield 'derived crop width below two' => [$center, 100, 2];
        yield 'derived crop height below two' => [$center, 1, 100];
    }

    private function buildCenter(string $ratio, int $width, int $height): string
    {
        return (new FfmpegReframeFilterBuilder())->build(
            ReframePlan::center(AspectRatio::fromString($ratio)),
            $width,
            $height
        );
    }
}
