<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReframePlanValidatorTest extends TestCase
{
    public function testItAcceptsAnOriginalSubmissionWithoutReframeData(): void
    {
        $plan = (new ReframePlanValidator())->validate(ReframeSubmission::original(), 2000);

        self::assertSame('original', $plan->aspectRatio()->value());
        self::assertSame('original', $plan->mode());
        self::assertSame([], $plan->keyframes());
        self::assertNull($plan->detectorVersion());
    }

    public function testItAcceptsAValidCenterSubmission(): void
    {
        $plan = (new ReframePlanValidator())->validate(
            new ReframeSubmission('9:16', 'center', '', '', '[]'),
            2000
        );

        self::assertSame('9:16', $plan->aspectRatio()->value());
        self::assertSame('center', $plan->mode());
        self::assertSame([], $plan->keyframes());
    }

    public function testItAcceptsCanonicalManualFocusAsAnInitialManualKeyframe(): void
    {
        $plan = (new ReframePlanValidator())->validate(
            new ReframeSubmission('1:1', 'manual', '0.225', '1', ''),
            2000
        );

        self::assertSame('manual', $plan->mode());
        self::assertCount(1, $plan->keyframes());
        self::assertSame(0, $plan->keyframes()[0]->atMs());
        self::assertSame('0.225000', $plan->keyframes()[0]->centerXDecimal());
        self::assertSame('1.000000', $plan->keyframes()[0]->centerYDecimal());
        self::assertSame('manual', $plan->keyframes()[0]->source());
    }

    public function testItAcceptsCanonicalAutomaticKeyframesAndKeepsDetectorServerOwned(): void
    {
        $submission = new ReframeSubmission(
            '4:5', 'auto', '', '',
            '[{"at_ms":0,"center_x":0.225,"center_y":0.5},{"at_ms":2000,"center_x":0.775,"center_y":0.5}]'
        );
        $plan = (new ReframePlanValidator())->validate($submission, 2000);

        self::assertSame('tasks-vision-1.0.1/blazeface-short-f16-r1', $plan->detectorVersion());
        self::assertSame('auto', $plan->mode());
        self::assertCount(2, $plan->keyframes());
        self::assertSame('detected', $plan->keyframes()[0]->source());
        self::assertSame('0.775000', $plan->keyframes()[1]->centerXDecimal());
    }

    /** @dataProvider invalidSubmissions */
    public function testItRejectsUntrustedOrInconsistentSubmissions(ReframeSubmission $submission, int $durationMs): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReframePlanValidator())->validate($submission, $durationMs);
    }

    /** @return iterable<string, array{ReframeSubmission, int}> */
    public function invalidSubmissions(): iterable
    {
        yield 'server owned field' => [new ReframeSubmission('original', 'original', '', '', '', true), 0];
        yield 'original ratio with center mode' => [new ReframeSubmission('original', 'center', '', '', ''), 0];
        yield 'original focus' => [new ReframeSubmission('original', 'original', '0.5', '', ''), 0];
        yield 'center focus' => [new ReframeSubmission('9:16', 'center', '0.5', '', ''), 0];
        yield 'center keyframes' => [new ReframeSubmission('9:16', 'center', '', '', '[ ]'), 0];
        yield 'manual original ratio' => [new ReframeSubmission('original', 'manual', '0.5', '0.5', ''), 0];
        yield 'manual exponent focus' => [new ReframeSubmission('9:16', 'manual', '1e-1', '0.5', ''), 0];
        yield 'manual negative zero focus' => [new ReframeSubmission('9:16', 'manual', '-0', '0.5', ''), 0];
        yield 'manual keyframes' => [new ReframeSubmission('9:16', 'manual', '0.5', '0.5', '[ ]'), 0];
        yield 'long center reframe' => [new ReframeSubmission('9:16', 'center', '', '', ''), 90001];
        yield 'oversized json' => [new ReframeSubmission('9:16', 'auto', '', '', '[' . str_repeat(' ', 16384) . ']'), 0];
        yield 'numeric string' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":"0.5","center_y":0.5}]'), 0];
        yield 'exponent coordinate' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":1e-1,"center_y":0.5}]'), 0];
        yield 'negative zero coordinate' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":-0,"center_y":0.5}]'), 0];
        yield 'non finite coordinate' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":NaN,"center_y":0.5}]'), 0];
        yield 'seventh decimal' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":0.1234567,"center_y":0.5}]'), 0];
        yield 'extra key' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":0.5,"center_y":0.5,"source":"detected"}]'), 0];
        yield 'duplicate key' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"at_ms":0,"center_x":0.5,"center_y":0.5}]'), 0];
        yield 'associative json' => [new ReframeSubmission('9:16', 'auto', '', '', '{"at_ms":0,"center_x":0.5,"center_y":0.5}'), 0];
        yield 'reordered keys' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"center_x":0.5,"at_ms":0,"center_y":0.5}]'), 0];
        yield 'escaped key' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at\\u005fms":0,"center_x":0.5,"center_y":0.5}]'), 0];
        yield 'escaped numeric string' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":"0\\u002e5","center_y":0.5}]'), 0];
        yield 'unicode escaped numeric' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":\\u0030.5,"center_y":0.5}]'), 0];
        yield 'leading non json byte' => [new ReframeSubmission('9:16', 'auto', '', '', 'x[]'), 0];
        yield 'trailing non json byte' => [new ReframeSubmission('9:16', 'auto', '', '', '[]x'), 0];
        yield 'vertical whitespace outside json set' => [new ReframeSubmission('9:16', 'auto', '', '', "\v[]"), 0];
        yield 'duplicate time' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":0.5,"center_y":0.5},{"at_ms":0,"center_x":0.5,"center_y":0.5}]'), 0];
        yield 'out of order time' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":1000,"center_x":0.5,"center_y":0.5},{"at_ms":0,"center_x":0.5,"center_y":0.5}]'), 1000];
        yield 'first time not zero' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":1,"center_x":0.5,"center_y":0.5}]'), 1];
        yield 'last time misses duration' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":0.5,"center_y":0.5}]'), 1];
        yield 'single automatic point at zero duration' => [new ReframeSubmission('9:16', 'auto', '', '', '[{"at_ms":0,"center_x":0.5,"center_y":0.5}]'), 0];
        yield 'more than thirty two keyframes' => [new ReframeSubmission('9:16', 'auto', '', '', self::keyframesJson(33, 32000)), 32000];
    }

    private static function keyframesJson(int $count, int $lastAtMs): string
    {
        $items = [];
        for ($index = 0; $index < $count; $index++) {
            $atMs = $index === $count - 1 ? $lastAtMs : $index * 1000;
            $items[] = '{"at_ms":' . $atMs . ',"center_x":0.5,"center_y":0.5}';
        }

        return '[' . implode(',', $items) . ']';
    }
}
