<?php

declare(strict_types=1);

namespace App\Services;

use App\Queue\ProcessingErrorCatalog;
use App\Repositories\ClipRepository;

final class ClipStatusService
{
    private const ASPECT_RATIOS = ['original', '9:16', '1:1', '16:9', '4:5'];
    private const REFRAME_MODES = ['original', 'center', 'manual', 'auto'];

    /** @var callable(int, int): ?array */
    private $status;

    /** @param ClipRepository|callable(int, int): ?array $clips */
    public function __construct(ClipRepository|callable $clips, private $subtitleStatus = null)
    {
        $this->status = $clips instanceof ClipRepository
            ? static fn (int $clipId, int $userId): ?array => $clips->statusForOwnedClip($clipId, $userId)
            : $clips;
    }

    /** @return array{id:int,status:string,stage:string,message:string,render_start_time:?float,render_end_time:?float,output_aspect_ratio:string,reframe_mode:string,thumbnail_url:?string,download_url:?string,updated_at:string}|null */
    public function forOwnedClip(int $clipId, int $userId): ?array
    {
        if ($clipId < 1 || $userId < 1) {
            return null;
        }
        $row = ($this->status)($clipId, $userId);
        if (!is_array($row)) {
            return null;
        }
        $status = (string) ($row['status'] ?? 'suggested');
        [$stage, $message] = $this->copyFor($status, $row['render_error_code'] ?? null);
        if ($status === 'queued' && is_callable($this->subtitleStatus)) {
            $captions = ($this->subtitleStatus)($clipId, $userId);
            if ($captions === 'queued') {
                [$stage, $message] = ['Na fila de legendas', 'A transcrição está aguardando processamento.'];
            } elseif ($captions === 'processing') {
                [$stage, $message] = ['Gerando legendas', 'O áudio está sendo transcrito antes da renderização.'];
            }
        }
        [$aspectRatio, $reframeMode] = $this->publicReframe($row);
        $completed = $status === 'completed';

        return [
            'id' => $clipId,
            'status' => $status,
            'stage' => $stage,
            'message' => $message,
            'render_start_time' => ($row['render_start_time'] ?? null) === null ? null : (float) $row['render_start_time'],
            'render_end_time' => ($row['render_end_time'] ?? null) === null ? null : (float) $row['render_end_time'],
            'output_aspect_ratio' => $aspectRatio,
            'reframe_mode' => $reframeMode,
            'thumbnail_url' => $completed ? '/clips/' . $clipId . '/thumbnail' : null,
            'download_url' => $completed ? '/clips/' . $clipId . '/download' : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row @return array{string,string} */
    private function publicReframe(array $row): array
    {
        $aspectRatio = $row['output_aspect_ratio'] ?? 'original';
        $reframeMode = $row['reframe_mode'] ?? 'original';
        if (!is_string($aspectRatio)
            || !is_string($reframeMode)
            || !in_array($aspectRatio, self::ASPECT_RATIOS, true)
            || !in_array($reframeMode, self::REFRAME_MODES, true)
            || (($aspectRatio === 'original') !== ($reframeMode === 'original'))
        ) {
            return ['original', 'original'];
        }

        return [$aspectRatio, $reframeMode];
    }

    /** @return array{string, string} */
    private function copyFor(string $status, mixed $errorCode): array
    {
        if ($status === 'failed') {
            $message = is_string($errorCode) ? ProcessingErrorCatalog::message($errorCode) : null;

            return ['Falhou', $message ?? 'Não foi possível renderizar este corte.'];
        }

        return match ($status) {
            'approved' => ['Aprovado', 'Preparando a renderização do corte.'],
            'queued' => ['Na fila para renderização', 'O corte está aguardando a renderização.'],
            'rendering' => ['Renderizando vídeo', 'O vídeo está sendo renderizado.'],
            'completed' => ['Concluído', 'O vídeo está pronto para download.'],
            default => ['Sugestão pronta', 'Revise o intervalo e aprove este corte para renderizar.'],
        };
    }
}
