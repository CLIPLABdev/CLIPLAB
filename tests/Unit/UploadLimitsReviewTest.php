<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Core\View;
use App\Media\ProjectReceipt;
use App\Media\UploadLimits;
use PHPUnit\Framework\TestCase;

final class UploadLimitsReviewTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 42];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testUnsupportedPhpQuantitySuffixFallsBackToItsNumericPrefixInBytes(): void
    {
        self::assertSame(2, UploadLimits::parseIniBytes('2T'));
        self::assertSame(2, UploadLimits::parseIniBytes('2t'));
    }

    public function testSupportedPhpQuantitySuffixSaturatesOnIntegerOverflow(): void
    {
        self::assertSame(PHP_INT_MAX, UploadLimits::parseIniBytes(PHP_INT_MAX . 'G'));
    }

    public function testCreationViewNeverRoundsTheDisplayedLimitAboveTheExactFormLimit(): void
    {
        $controller = new ProjectController(
            new View(),
            new UploadReviewProjectCreator(),
            static fn (): array => [],
            null,
            41523610
        );

        $html = $controller->create()->body();

        self::assertStringContainsString('name="MAX_FILE_SIZE" value="41523610"', $html);
        self::assertStringContainsString('até 39 MB', $html);
        self::assertStringNotContainsString('até 40 MB', $html);
    }
}

final class UploadReviewProjectCreator implements ProjectCreator
{
    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt
    {
        return new ProjectReceipt(1, 'queued', true);
    }

    public function fromDirectUrl(int $userId, array $input): ProjectReceipt
    {
        return new ProjectReceipt(1, 'queued', true);
    }
}
