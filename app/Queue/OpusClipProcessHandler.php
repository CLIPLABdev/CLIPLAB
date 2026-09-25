<?php

declare(strict_types=1);

namespace App\Queue;

use App\Contracts\PrivateStorage;
use App\Media\StoredObject;
use App\OpusClip\OpusClipClient;
use App\Process\ProcessRunner;
use App\Repositories\AiAnalysisRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\CreditReservationService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Substitui analyze_video + generate_clips + render_clip quando o projeto
 * usa a OpusClip: envia o vídeo, aguarda o processamento e materializa os
 * clipes já prontos (sem FFmpeg local).
 *
 * Reaproveita a tabela ai_analyses como "registro do lote": a coluna
 * provider_request_id guarda o projectId retornado pela OpusClip.
 */
final class OpusClipProcessHandler implements JobHandler
{
    private const POLL_SECONDS = 20;
    private const CHECKPOINT_DEFER_SECONDS = 15;

    public function __construct(
        private PDO $pdo,
        private AiAnalysisRepository $analyses,
        private ProjectSourceRepository $sources,
        private PrivateStorage $storage,
        private OpusClipClient $opusClip,
        private ProcessingEffectGuard $effects,
        private CreditReservationService $credits,
        private ?\Closure $rawClipObserver = null,
        private ?ProjectRepository $projects = null,
        private ?ProcessRunner $ffmpegRunner = null,
        private string $ffmpegBinary = 'ffmpeg'
    ) {
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        $payload = $job->payload();
        if (!isset($payload['analysis_id'], $payload['source_id'], $payload['reservation_id'])
            || !is_int($payload['analysis_id']) || !is_int($payload['source_id']) || !is_int($payload['reservation_id'])
        ) {
            return JobOutcome::failed('analysis_not_found', 'Registro de processamento não encontrado.');
        }
        $analysisId = $payload['analysis_id'];
        $sourceId = $payload['source_id'];
        $reservationId = $payload['reservation_id'];

        try {
            $analysis = $this->analyses->findForProject($analysisId, $job->projectId());
        } catch (\PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }
        if ($analysis === null) {
            return JobOutcome::failed('analysis_not_found', 'Registro de processamento não encontrado.');
        }
        if ($analysis['status'] === 'completed') {
            return JobOutcome::completed();
        }
        if ($analysis['status'] === 'failed') {
            return JobOutcome::failed(
                (string) ($analysis['error_code'] ?? 'opusclip_failed'),
                (string) ($analysis['error_message'] ?? 'O processamento pela OpusClip falhou.')
            );
        }

        $opusProjectId = $analysis['provider_request_id'] ?? null;

        if ($opusProjectId === null) {
            return $this->startOpusClipProject($job, $analysisId, $sourceId, $reservationId);
        }

        return $this->pollAndMaterialize($job, $analysisId, (string) $opusProjectId, $reservationId);
    }

    private function startOpusClipProject(ClaimedJob $job, int $analysisId, int $sourceId, int $reservationId): JobOutcome
    {
        try {
            $ready = $this->sources->findReadyForAnalysis($sourceId, $job->projectId());
        } catch (\PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }
        if (!is_array($ready) || !($ready['source'] ?? null) instanceof \App\Media\ProjectSource) {
            return JobOutcome::failed('analysis_not_found', 'Origem do vídeo não encontrada.');
        }
        $source = $ready['source'];

        try {
            $this->updateAnalysisStatus($analysisId, 'uploading');
            $this->advanceProject($job->projectId(), 'uploading_ai');

            $absolutePath = $this->storage->absolutePath($source->objectKey());
            $fileSize = filesize($absolutePath);
            if ($fileSize === false) {
                throw new RuntimeException('Arquivo de origem não pôde ser lido.');
            }

            $link = $this->opusClip->createUploadLink();
            $resumableUrl = $this->opusClip->startResumableSession($link['url'], basename($absolutePath), $fileSize);
            $this->opusClip->uploadFile($resumableUrl, $absolutePath);
            $project = $this->opusClip->createProject($link['uploadId']);

            $this->pdo->prepare(
                "UPDATE ai_analyses SET status = 'waiting_file', provider_request_id = :pid,
                        error_code = NULL, error_message = NULL
                 WHERE id = :id"
            )->execute(['id' => $analysisId, 'pid' => $project['projectId']]);
        } catch (Throwable) {
            return $this->fail($analysisId, $reservationId, 'opusclip_unavailable', $job->projectId());
        }

        return JobOutcome::deferred(self::POLL_SECONDS);
    }

    private function pollAndMaterialize(ClaimedJob $job, int $analysisId, string $opusProjectId, int $reservationId): JobOutcome
    {
        try {
            $clips = $this->opusClip->getClipsForProject($opusProjectId);
        } catch (Throwable) {
            return $this->fail($analysisId, $reservationId, 'opusclip_unavailable', $job->projectId());
        }

        if ($clips === []) {
            // Ainda processando do lado da OpusClip; tenta de novo mais tarde.
            $this->advanceProject($job->projectId(), 'analyzing');
            return JobOutcome::deferred(self::POLL_SECONDS);
        }

        try {
            $applied = $this->effects->apply($job, function () use ($job, $analysisId, $clips, $reservationId): void {
                $this->materializeClips($job->projectId(), $analysisId, $clips);
                $this->analyses->markCompleted($analysisId);
                $this->credits->consume($reservationId);
                // Os clipes da OpusClip já chegam renderizados: o projeto passa a "Concluído" (100%).
                $this->projects?->synchronizeRenderState($job->projectId());
            });
        } catch (Throwable) {
            return $this->fail($analysisId, $reservationId, 'processing_persistence_failed', $job->projectId());
        }

        return $applied ? JobOutcome::completed() : JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
    }

    /** @param list<array<string, mixed>> $clips */
    private function materializeClips(int $projectId, int $analysisId, array $clips): void
    {
        // Idempotência: se essa análise já tem clipes materializados (nova tentativa
        // depois de uma falha de lease), não duplica.
        $existing = $this->pdo->prepare('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = :id');
        $existing->execute(['id' => $analysisId]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO clips
                (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time,
                 duration_seconds, viral_score, hook, reason, category, status,
                 output_file, output_size_bytes, thumbnail, thumbnail_size_bytes, rendered_at)
             VALUES
                (:project_id, :analysis_id, :suggestion_index, :title, :start_time, :end_time,
                 :duration_seconds, :viral_score, :hook, :reason, :category, \'completed\',
                 :output_file, :output_size_bytes, :thumbnail, :thumbnail_size_bytes, UTC_TIMESTAMP())'
        );

        foreach (array_values($clips) as $index => $raw) {
            if (is_callable($this->rawClipObserver)) {
                // Log da resposta crua para conferência dos nomes de campo reais.
                ($this->rawClipObserver)(['project_id' => $projectId, 'analysis_id' => $analysisId, 'raw' => $raw]);
            }

            // uriForExport é o arquivo final (qualidade de download); uriForPreview é a versão de prévia.
            $video = $this->downloadClipAsset($projectId, $raw, ['uriForExport', 'exportUrl', 'downloadUrl', 'uriForPreview', 'videoUrl', 'url']);
            if ($video === null) {
                // Sem URL de vídeo utilizável, pula este clipe em vez de falhar o lote inteiro.
                continue;
            }
            // A capa é gerada a partir do próprio corte, para nunca mostrar um quadro do vídeo inteiro.
            $thumbnail = $this->thumbnailFromClip($projectId, $video)
                ?? $this->downloadClipAsset($projectId, $raw, ['uriForThumbnail', 'thumbnailUrl', 'coverUrl', 'thumbnail']);

            $title = $this->firstString($raw, ['title', 'name', 'headline']) ?? ('Clipe OpusClip ' . ($index + 1));
            $hook = $this->firstString($raw, ['description', 'hook', 'caption']) ?? $title;
            // durationMs é o campo real (milissegundos); os demais ficam como fallback.
            $durationMs = $this->firstFloat($raw, ['durationMs']);
            $duration = $durationMs !== null ? $durationMs / 1000 : ($this->firstFloat($raw, ['duration', 'durationSeconds', 'length']) ?? 0.0);
            $score = $this->firstInt($raw, ['score', 'viralityScore', 'virality']) ?? 70;
            [$start, $end] = OpusClipTimeRanges::sourceWindow($raw['timeRanges'] ?? null) ?? [0.0, max($duration, 0.0)];
            if ($duration <= 0.0) {
                $duration = max(0.0, $end - $start);
            }
            $category = $this->firstString($raw, ['genre', 'subgenre']) ?? 'Corte automático';

            $insert->execute([
                'project_id' => $projectId,
                'analysis_id' => $analysisId,
                'suggestion_index' => $index,
                'title' => mb_substr($title, 0, 180),
                'start_time' => number_format(max($start, 0.0), 3, '.', ''),
                'end_time' => number_format(max($end, $start), 3, '.', ''),
                'duration_seconds' => number_format(max($duration, 0.0), 3, '.', ''),
                'viral_score' => max(0, min(100, $score)),
                'hook' => mb_substr($hook, 0, 500),
                'reason' => mb_substr('Trecho escolhido automaticamente pela IA por ter começo, meio e fim com potencial de engajamento.', 0, 1000),
                'category' => mb_substr($category, 0, 32),
                'output_file' => $video->objectKey(),
                'output_size_bytes' => $video->sizeBytes(),
                'thumbnail' => $thumbnail?->objectKey(),
                'thumbnail_size_bytes' => $thumbnail?->sizeBytes(),
            ]);
        }
    }

    private function thumbnailFromClip(int $projectId, ?StoredObject $video): ?StoredObject
    {
        if ($video === null || $this->ffmpegRunner === null) {
            return null;
        }

        return (new \App\Media\ClipFrameThumbnail($this->ffmpegRunner, $this->ffmpegBinary))->fromVideo(
            $this->storage,
            $video->objectKey(),
            'clips/opusclip/' . $projectId . '/' . bin2hex(random_bytes(16)) . '.jpg'
        );
    }

    private function advanceProject(int $projectId, string $status): void
    {
        try {
            $this->projects?->advanceProcessingState($projectId, $status);
        } catch (Throwable) {
            // O status visual nunca interrompe o processamento.
        }
    }

    /** @param array<string, mixed> $raw @param list<string> $keys */
    private function downloadClipAsset(int $projectId, array $raw, array $keys): ?StoredObject
    {
        $url = null;
        foreach ($keys as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && $raw[$key] !== '') {
                $url = $raw[$key];
                break;
            }
        }
        if ($url === null) {
            return null;
        }

        $stream = fopen($url, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'mp4';
            $objectKey = 'clips/opusclip/' . $projectId . '/' . bin2hex(random_bytes(16)) . '.' . $extension;

            return $this->storage->putStream($stream, $objectKey, 524288000);
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param array<string, mixed> $raw @param list<string> $keys */
    private function firstString(array $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                return $raw[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $raw @param list<string> $keys */
    private function firstFloat(array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return (float) $raw[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $raw @param list<string> $keys */
    private function firstInt(array $raw, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return (int) $raw[$key];
            }
        }

        return null;
    }

    private function updateAnalysisStatus(int $analysisId, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE ai_analyses SET status = :status WHERE id = :id AND status NOT IN (\'completed\', \'failed\')'
        )->execute(['id' => $analysisId, 'status' => $status]);
    }

    private function fail(int $analysisId, int $reservationId, string $code, ?int $projectId = null): JobOutcome
    {
        $message = ProcessingErrorCatalog::requireMessage($code);
        try {
            $this->credits->refund($reservationId, $code);
            $this->analyses->markFailed($analysisId, $code, $message);
            if ($projectId !== null) {
                try {
                    $this->projects?->advanceProcessingState($projectId, 'failed', $code, mb_substr($message, 0, 255));
                } catch (Throwable) {
                    // O status visual nunca bloqueia o reembolso.
                }
            }
        } catch (Throwable) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }

        return JobOutcome::failed($code, $message);
    }
}
