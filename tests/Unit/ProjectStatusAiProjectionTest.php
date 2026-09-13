<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProjectStatusService;
use PHPUnit\Framework\TestCase;

final class ProjectStatusAiProjectionTest extends TestCase
{
    public function testSuggestionsReadyExposesOnlyTheSafeTransitionFields(): void
    {
        $service = new ProjectStatusService(static fn (): array => [
            'id' => 42,
            'status' => 'suggestions_ready',
            'progress' => 100,
            'error_code' => null,
            'error_message' => null,
            'analysis_status' => 'completed',
            'suggestions_count' => 3,
            'validated_response_json' => '{"secret":"never"}',
            'gemini_file_uri' => 'https://provider.example/private',
            'provider_request_id' => 'provider-private',
            'updated_at' => '2026-09-04 10:00:00',
        ]);

        $status = $service->forOwnedProject(42, 7);

        self::assertNotNull($status);
        self::assertSame('Sugestões prontas', $status['stage']);
        self::assertSame('completed', $status['analysis_status']);
        self::assertSame(3, $status['suggestions_count']);
        self::assertSame('/projetos/42', $status['suggestions_url']);
        $encoded = json_encode($status, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('provider-private', $encoded);
        self::assertStringNotContainsString('never', $encoded);
        self::assertStringNotContainsString('provider.example', $encoded);
    }

    /** @dataProvider aiStages */
    public function testMapsEveryPersistedAiStage(string $status, string $stage): void
    {
        $service = new ProjectStatusService(static fn (): array => [
            'id' => 9,
            'status' => $status,
            'progress' => 80,
            'analysis_status' => 'uploading',
            'suggestions_count' => 0,
            'updated_at' => '2026-09-04 10:00:00',
        ]);

        self::assertSame($stage, $service->forOwnedProject(9, 7)['stage']);
    }

    /** @return iterable<string, array{string, string}> */
    public function aiStages(): iterable
    {
        yield 'queued' => ['ai_queued', 'Na fila da IA'];
        yield 'uploading' => ['uploading_ai', 'Enviando para a IA'];
        yield 'waiting' => ['waiting_ai_file', 'Preparando o vídeo na IA'];
        yield 'analyzing' => ['analyzing', 'Analisando com IA'];
        yield 'clips' => ['identifying_clips', 'Identificando cortes'];
        yield 'credits' => ['awaiting_credits', 'Aguardando créditos'];
    }

    public function testAiFailureUsesTheFixedCatalogAndNeverStoredMessage(): void
    {
        $service = new ProjectStatusService(static fn (): array => [
            'id' => 11,
            'status' => 'failed',
            'progress' => 100,
            'error_code' => 'ai_rate_limited',
            'error_message' => 'key-and-private-provider-detail',
            'analysis_status' => 'failed',
            'suggestions_count' => 0,
            'updated_at' => '2026-09-04 10:00:00',
        ]);

        $status = $service->forOwnedProject(11, 7);

        self::assertSame('O serviço de IA está temporariamente sobrecarregado.', $status['message']);
        self::assertStringNotContainsString('private-provider', json_encode($status, JSON_THROW_ON_ERROR));
    }
}
