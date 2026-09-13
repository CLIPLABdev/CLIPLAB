<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectSuggestionController;
use App\Core\Request;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ProjectSuggestionsAccessTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testForeignUnknownAndInvalidProjectPagesAreIndistinguishable(): void
    {
        $controller = $this->controller(static fn (): ?array => null, static fn (): array => []);

        $foreign = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31']);
        $unknown = $controller->show(Request::fake('GET', '/projetos/999999999'), ['id' => '999999999']);
        $invalid = $controller->show(Request::fake('GET', '/projetos/not-a-number'), ['id' => 'not-a-number']);

        self::assertSame(404, $foreign->status());
        self::assertSame($unknown->body(), $foreign->body());
        self::assertSame($invalid->body(), $foreign->body());
    }

    public function testOwnedPageProjectsEveryValueBeforeRendering(): void
    {
        $project = static fn (int $projectId, int $userId): array => [
            'id' => $projectId,
            'name' => '<script>project-secret</script>',
            'status' => 'suggestions_ready',
            'progress' => 100,
            'analysis_status' => 'completed',
            'video_summary' => '<img src=x onerror=summary-secret>',
            'gemini_file_uri' => 'gemini-secret',
            'validated_response_json' => 'json-secret',
            'provider_request_id' => 'request-secret',
            'reservation_id' => 99,
            'updated_at' => '2026-09-04 10:00:00',
        ];
        $clips = static fn (): array => [[
            'id' => 71,
            'title' => '<script>clip-secret</script>',
            'start_time' => '12.500',
            'end_time' => '42.500',
            'duration_seconds' => '30.000',
            'viral_score' => 94,
            'hook' => '<b>hook-safe</b>',
            'reason' => '<img src=x onerror=reason-secret>',
            'category' => 'insight',
            'status' => 'suggested',
            'output_file' => 'output-secret.mp4',
            'thumbnail' => 'thumb-secret.jpg',
        ]];
        $controller = $this->controller($project, $clips);

        $response = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31']);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('&lt;script&gt;project-secret&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;clip-secret&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;hook-safe&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<script>project-secret</script>', $html);
        foreach (['gemini-secret', 'json-secret', 'request-secret', 'output-secret.mp4', 'thumb-secret.jpg'] as $private) {
            self::assertStringNotContainsString($private, $html);
        }
    }

    private function controller(callable $project, callable $clips): ProjectSuggestionController
    {
        return new ProjectSuggestionController(
            new View(),
            $project,
            $clips,
            static fn (int $userId): array => [
                'id' => $userId,
                'name' => 'Ana',
                'email' => 'ana@example.test',
                'credits' => 10,
                'plan_name' => 'Free',
                'monthly_minutes' => 60,
            ]
        );
    }
}
