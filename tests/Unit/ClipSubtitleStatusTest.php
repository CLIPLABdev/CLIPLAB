<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClipStatusService;
use PHPUnit\Framework\TestCase;

final class ClipSubtitleStatusTest extends TestCase
{
    public function testQueuedCaptionsExposeTruthfulStageWithoutDownload(): void
    {
        $service = new ClipStatusService(static fn (): array => ['status'=>'queued'], static fn (): string => 'queued');
        $state = $service->forOwnedClip(7, 3);
        self::assertSame('Na fila de legendas', $state['stage']);
        self::assertSame('queued', $state['status']);
        self::assertNull($state['download_url']);
    }

    public function testActiveCaptionJobShowsTranscriptionAndDoesNotInventRenderProgress(): void
    {
        $service = new ClipStatusService(static fn (): array => ['status'=>'queued'], static fn (): string => 'processing');
        self::assertSame('Gerando legendas', $service->forOwnedClip(7, 3)['stage']);
    }

    public function testForeignClipDoesNotTriggerSubtitleLookupAndCompletedClipRetainsDownload(): void
    {
        $lookup = static function (): void { self::fail('Caption lookup must not run here.'); };
        self::assertNull((new ClipStatusService(static fn () => null, $lookup))->forOwnedClip(7, 99));
        $done = (new ClipStatusService(static fn () => ['status'=>'completed'], $lookup))->forOwnedClip(7, 3);
        self::assertSame('/clips/7/download', $done['download_url']);
    }
}
