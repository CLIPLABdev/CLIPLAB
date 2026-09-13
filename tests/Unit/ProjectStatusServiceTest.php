<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProjectStatusService;
use PHPUnit\Framework\TestCase;

final class ProjectStatusServiceTest extends TestCase
{
    public function testOwnerReceivesOnlyThePublicStatusProjection(): void
    {
        $service = new ProjectStatusService(static fn (int $projectId, int $userId): ?array => [
            'id' => $projectId,
            'status' => 'ready',
            'progress' => 100,
            'error_message' => null,
            'duration_seconds' => 91,
            'width' => 1280,
            'height' => 720,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'has_audio' => 1,
            'size_bytes' => 2048,
            'original_name' => 'entrevista.mp4',
            'updated_at' => '2026-09-03 12:00:00',
            'source_url' => 'https://secret.example/video.mp4?token=secret',
            'object_key' => 'users/7/private.mp4',
            'analysis_status' => 'completed',
            'suggestions_count' => 2,
        ]);

        $status = $service->forOwnedProject(31, 7);

        self::assertNotNull($status);
        self::assertSame(['id', 'status', 'progress', 'stage', 'message', 'media', 'updated_at', 'analysis_status', 'suggestions_count', 'suggestions_url'], array_keys($status));
        self::assertSame(['duration_seconds', 'width', 'height', 'video_codec', 'audio_codec', 'has_audio', 'size_bytes', 'original_name'], array_keys($status['media']));
        self::assertSame('Pronto', $status['stage']);
        self::assertTrue($status['media']['has_audio']);
        self::assertArrayNotHasKey('source_url', $status);
        self::assertArrayNotHasKey('object_key', $status['media']);
        self::assertSame('completed', $status['analysis_status']);
        self::assertSame(2, $status['suggestions_count']);
        self::assertNull($status['suggestions_url']);
    }

    public function testFailedStatusNeverLeaksTheStoredTechnicalMessage(): void
    {
        $service = new ProjectStatusService(static fn (): ?array => [
            'id' => 32,
            'status' => 'failed',
            'progress' => 100,
            'error_code' => 'database_internal_error',
            'error_message' => 'Password=super-secret em C:\\private\\worker.php:91',
            'updated_at' => '2026-09-03 12:00:00',
            'analysis_status' => 'failed',
            'suggestions_count' => 0,
        ]);

        $status = $service->forOwnedProject(32, 7);

        self::assertNotNull($status);
        self::assertSame('Não foi possível validar a origem do vídeo.', $status['message']);
        self::assertStringNotContainsString('super-secret', json_encode($status, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('C:\\private', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function testMissingOrForeignProjectHasNoProjection(): void
    {
        $service = new ProjectStatusService(static fn (): ?array => null);

        self::assertNull($service->forOwnedProject(31, 8));
    }
}
