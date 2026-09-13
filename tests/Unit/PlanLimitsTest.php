<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Plans\PlanLimits;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlanLimitsTest extends TestCase
{
    public function testLegacyFeaturesKeepSupportedFlagsAndReceiveSafeDefaults(): void
    {
        $limits = PlanLimits::fromFeatures([
            'exports_hd' => true,
            'priority_processing' => false,
        ]);

        self::assertSame([
            'exports_hd' => true,
            'priority_processing' => false,
            'team_access' => false,
            'limits' => [
                'max_upload_bytes' => 104857600,
                'storage_bytes' => 1073741824,
            ],
        ], $limits->toArray());
    }

    public function testExactCommercialFeaturesRoundTripWithoutCoercion(): void
    {
        $features = [
            'exports_hd' => true,
            'priority_processing' => true,
            'team_access' => true,
            'limits' => [
                'max_upload_bytes' => 524288000,
                'storage_bytes' => 107374182400,
            ],
        ];

        self::assertSame($features, PlanLimits::fromFeatures($features)->toArray());
    }

    /** @dataProvider invalidFeatures */
    public function testRejectsUnknownKeysInvalidTypesAndNonPositiveBytes(array $features): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanLimits::fromFeatures($features);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public function invalidFeatures(): iterable
    {
        yield 'unknown feature' => [['exports_hd' => false, 'surprise' => true]];
        yield 'unknown limit' => [['limits' => ['max_upload_bytes' => 1, 'storage_bytes' => 1, 'projects' => 2]]];
        yield 'flag is not boolean' => [['exports_hd' => 1]];
        yield 'limits is not an object' => [['limits' => 'unlimited']];
        yield 'explicit null limits' => [['limits' => null]];
        yield 'explicit null upload' => [['limits' => ['max_upload_bytes' => null]]];
        yield 'negative upload' => [['limits' => ['max_upload_bytes' => -1]]];
        yield 'zero storage' => [['limits' => ['storage_bytes' => 0]]];
        yield 'numeric string' => [['limits' => ['max_upload_bytes' => '104857600']]];
    }
}
