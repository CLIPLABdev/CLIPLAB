<?php

declare(strict_types=1);

namespace App\Services;

use App\Queue\ProcessingErrorCatalog;
use App\Repositories\ProjectRepository;

final class ProjectStatusService
{
    /** @var callable(int, int): ?array */
    private $status;

    /** @param ProjectRepository|callable(int, int): ?array $projects */
    public function __construct(ProjectRepository|callable $projects)
    {
        $this->status = $projects instanceof ProjectRepository
            ? static fn (int $projectId, int $userId): ?array => $projects->statusForOwnedProject($projectId, $userId)
            : $projects;
    }

    /** @return array{id:int,status:string,progress:int,stage:string,message:string,media:array<string,mixed>,updated_at:string,analysis_status:?string,suggestions_count:int,suggestions_url:?string}|null */
    public function forOwnedProject(int $projectId, int $userId): ?array
    {
        if ($projectId < 1 || $userId < 1) {
            return null;
        }
        $row = ($this->status)($projectId, $userId);
        if (!is_array($row)) {
            return null;
        }

        $status = (string) ($row['status'] ?? 'queued');
        [$stage, $message] = $this->copyFor($status, $row['error_code'] ?? null);
        if (in_array($status, ['ai_queued', 'uploading_ai', 'waiting_ai_file', 'analyzing'], true)
            && ($row['analysis_job_status'] ?? null) === 'retry'
            && in_array($row['analysis_job_error_code'] ?? null, ['ai_unavailable', 'ai_timeout', 'ai_rate_limited'], true)
        ) {
            $stage = 'Aguardando nova tentativa';
            $message = $this->retryMessage($row);
        }

        return [
            'id' => (int) $row['id'],
            'status' => $status,
            'progress' => max(0, min(100, (int) ($row['progress'] ?? 0))),
            'stage' => $stage,
            'message' => $message,
            'media' => [
                'duration_seconds' => $this->nullableInt($row['duration_seconds'] ?? null),
                'width' => $this->nullableInt($row['width'] ?? null),
                'height' => $this->nullableInt($row['height'] ?? null),
                'video_codec' => $this->nullableString($row['video_codec'] ?? null),
                'audio_codec' => $this->nullableString($row['audio_codec'] ?? null),
                'has_audio' => (bool) ($row['has_audio'] ?? false),
                'size_bytes' => $this->nullableInt($row['size_bytes'] ?? null),
                'original_name' => $this->nullableString($row['original_name'] ?? null),
            ],
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'analysis_status' => $this->nullableString($row['analysis_status'] ?? null),
            'suggestions_count' => max(0, (int) ($row['suggestions_count'] ?? 0)),
            'suggestions_url' => in_array($status, ['suggestions_ready', 'rendering', 'completed'], true)
                ? '/projetos/' . (int) $row['id']
                : null,
        ];
    }

    /** @return array{string, string} */
    private function copyFor(string $status, mixed $errorCode): array
    {
        if ($status === 'failed') {
            return ['Falhou', $this->publicErrorMessage($errorCode)];
        }
        if ($status === 'awaiting_credits') {
            return ['Aguardando créditos', $this->publicErrorMessage($errorCode, 'Créditos insuficientes para iniciar a análise.')];
        }

        return match ($status) {
            'receiving' => ['Recebendo', 'Seu vídeo está chegando ao estúdio.'],
            'fetching' => ['Baixando', 'Buscando o vídeo no link que você enviou.'],
            'probing' => ['Analisando', 'Conferindo duração, imagem e áudio.'],
            'ready' => ['Pronto', 'Vídeo conferido. A origem está pronta para continuar.'],
            'ai_queued' => ['Na fila da IA', 'Seu vídeo está na fila para análise da IA.'],
            'uploading_ai' => ['Enviando para a IA', 'Enviando seu vídeo para a IA analisar.'],
            'waiting_ai_file' => ['Preparando o vídeo na IA', 'A IA está preparando seu vídeo para leitura.'],
            'analyzing' => ['Analisando com IA', 'A IA está procurando trechos com potencial para cortes.'],
            'identifying_clips' => ['Identificando cortes', 'Organizando os trechos encontrados em sugestões de cortes.'],
            'suggestions_ready' => ['Sugestões prontas', 'Seus cortes sugeridos estão prontos para revisão.'],
            'rendering' => ['Renderizando', 'Preparando o arquivo do seu corte.'],
            'completed' => ['Concluído', 'Seus cortes exportados estão prontos para baixar.'],
            default => ['Na fila', 'Seu vídeo está na fila. O processamento começa em breve.'],
        };
    }

    /** @param array<string, mixed> $row */
    private function retryMessage(array $row): string
    {
        $message = 'O serviço de IA está temporariamente indisponível. Tentaremos novamente automaticamente.';
        $attempts = filter_var($row['analysis_job_attempts'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 7]]);
        $maximum = filter_var($row['analysis_job_max_attempts'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8]]);
        if ($attempts !== false && $maximum !== false && $attempts < $maximum) {
            $message .= ' Próxima tentativa: ' . ($attempts + 1) . ' de ' . $maximum . '.';
        }
        $availableAt = $row['analysis_job_available_at'] ?? null;
        if (is_string($availableAt) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $availableAt) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $availableAt, new \DateTimeZone('UTC'));
            if ($date !== false && $date->format('Y-m-d H:i:s') === $availableAt) {
                $message .= ' Agendada a partir de ' . $date->format('d/m/Y H:i:s') . ' UTC.';
            }
        }

        return $message;
    }

    private function publicErrorMessage(mixed $errorCode, string $fallback = 'Não foi possível validar a origem do vídeo.'): string
    {
        if (!is_string($errorCode)) {
            return $fallback;
        }
        $catalogMessage = ProcessingErrorCatalog::message($errorCode);
        if ($catalogMessage !== null) {
            return $catalogMessage;
        }
        $legacy = [
            'media_too_large' => 'O vídeo ultrapassa o limite permitido.',
            'unsafe_source_url' => 'A URL informada não pôde ser utilizada com segurança.',
            'remote_redirect_rejected' => 'A URL informada não pôde ser utilizada com segurança.',
            'invalid_media_container' => 'Não foi possível validar a origem do vídeo.',
            'invalid_media_metadata' => 'Não foi possível validar a origem do vídeo.',
        ];

        return $legacy[$errorCode] ?? $fallback;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
