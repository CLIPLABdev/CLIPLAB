<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectStatusController;
use App\Core\Request;
use App\Services\ProjectStatusService;
use PHPUnit\Framework\TestCase;

final class ProjectStatusEndpointTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testOwnedProjectReturnsJsonWithoutCacheOrPrivateFields(): void
    {
        $_SESSION = ['user_id' => 7];
        $controller = new ProjectStatusController(new ProjectStatusService(static fn (int $projectId, int $userId): ?array => $userId === 7 ? [
            'id' => $projectId, 'status' => 'probing', 'progress' => 70, 'error_message' => null,
            'duration_seconds' => null, 'width' => null, 'height' => null, 'video_codec' => null,
            'audio_codec' => null, 'has_audio' => 0, 'size_bytes' => 4096, 'original_name' => 'video.mp4',
            'updated_at' => '2026-09-03 12:00:00', 'source_url' => 'https://secret.example/video.mp4',
            'object_key' => 'private/video.mp4',
            'analysis_status' => 'generating', 'suggestions_count' => 0,
        ] : null));

        $response = $controller->show(Request::fake('GET', '/api/projects/31/status'), ['id' => '31']);
        $json = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame(['id', 'status', 'progress', 'stage', 'message', 'media', 'updated_at', 'analysis_status', 'suggestions_count', 'suggestions_url'], array_keys($json));
        self::assertArrayNotHasKey('source_url', $json);
        self::assertArrayNotHasKey('object_key', $json['media']);
        self::assertSame('generating', $json['analysis_status']);
        self::assertSame(0, $json['suggestions_count']);
        self::assertNull($json['suggestions_url']);
    }

    public function testAnotherUserGetsTheSameNotFoundAsAnUnknownProject(): void
    {
        $_SESSION = ['user_id' => 8];
        $controller = new ProjectStatusController(new ProjectStatusService(static fn (): ?array => null));

        $foreign = $controller->show(Request::fake('GET', '/api/projects/31/status'), ['id' => '31']);
        $unknown = $controller->show(Request::fake('GET', '/api/projects/999999999/status'), ['id' => '999999999']);

        self::assertSame(404, $foreign->status());
        self::assertSame($unknown->body(), $foreign->body());
        self::assertSame('{"error":"project_not_found"}', $foreign->body());
        self::assertSame('no-store', $foreign->header('Cache-Control'));
    }

    public function testGuestApiRequestGetsJsonUnauthorizedInsteadOfRedirect(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';

        $response = $router->dispatch(Request::fake('GET', '/api/projects/31/status'));

        self::assertSame(401, $response->status());
        self::assertSame('{"error":"unauthenticated"}', $response->body());
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    /** @dataProvider suggestionPageStatuses */
    public function testSuggestionPageRemainsAvailableDuringAndAfterRendering(string $status): void
    {
        $service = new ProjectStatusService(static fn (int $projectId): array => [
            'id' => $projectId,
            'status' => $status,
            'progress' => 96,
            'updated_at' => '2026-09-04 12:00:00',
            'analysis_status' => 'completed',
            'suggestions_count' => 2,
        ]);

        $payload = $service->forOwnedProject(31, 7);

        self::assertNotNull($payload);
        self::assertSame('/projetos/31', $payload['suggestions_url']);
    }

    /** @return iterable<string, array{string}> */
    public function suggestionPageStatuses(): iterable
    {
        yield 'suggestions ready' => ['suggestions_ready'];
        yield 'rendering' => ['rendering'];
        yield 'completed' => ['completed'];
    }
}
