<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\JobDispatcher;
use App\Exceptions\ClipRenderValidationException;
use App\Media\ClipRenderReceipt;
use App\Media\Editor\EditorOptions;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Media\Subtitles\SrtCodec;
use App\Media\Subtitles\TranscriptRevision;
use App\Repositories\ClipEditorRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ProjectRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ClipEditService
{
    private ?\Closure $logoOwned;
    public function __construct(
        private PDO $pdo,
        private ClipEditorRepository $editor,
        private ClipRepository $clips,
        private ProjectRepository $projects,
        private ClipRenderProfileRepository $profiles,
        private ReframePlanValidator $reframes,
        private JobDispatcher $jobs,
        private int $maxDurationSeconds = 180,
        ?callable $logoOwned = null,
        private ?SourceDurationPreflight $sourceDurations = null
    ) {
        $this->logoOwned = $logoOwned === null ? null : \Closure::fromCallable($logoOwned);
        if ($maxDurationSeconds < 1 || $maxDurationSeconds > 180) throw new InvalidArgumentException('Limite de corte inválido.');
    }

    public function request(int $clipId, int $userId, string $requestKey, string $startTime, string $endTime,
        EditorOptions $options, ReframeSubmission $reframe, string $transcriptMode, string $srt): ?ClipRenderReceipt
    {
        if ($clipId < 1 || $userId < 1) return null;
        $before=$this->editor->findOwned($clipId,$userId);
        if ($before===null) return null;
        $prepared=null;
        $existingBefore=$this->editor->findRequest($userId,$requestKey);
        if ($existingBefore===null && in_array($before['status'],['suggested','approved','completed','failed'],true)) {
            try {
                if ($this->sourceDurations===null || $this->pdo->inTransaction()) throw new RuntimeException('Precise source measurement is unavailable.');
                $prepared=$this->sourceDurations->prepare((int)$before['project_id'],$userId);
                if ($prepared===null) throw new RuntimeException('Precise source measurement is unavailable.');
            } catch (Throwable) {
                throw $this->precisionError((int)$before['project_id']);
            }
        }
        $owned = !$this->pdo->inTransaction();
        $savepoint = 'clip_edit_' . bin2hex(random_bytes(8));
        $owned ? $this->pdo->beginTransaction() : $this->pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            if (!$this->editor->lockOwnerAndProject($clipId, $userId)) {
                $this->finish($owned, $savepoint);
                return null;
            }
            $clip = $this->editor->findOwned($clipId, $userId);
            if ($clip === null) {
                $this->finish($owned, $savepoint);
                return null;
            }
            $projectId = (int)$clip['project_id'];
            if (preg_match('/^[a-f0-9]{64}$/D', $requestKey) !== 1) {
                throw new ClipRenderValidationException(['editor'=>'Atualize a página e envie novamente.'], $projectId);
            }
            $existing = $this->editor->findRequest($userId, $requestKey);
            if ($existing !== null) {
                if ((int)$existing['parent_clip_id'] !== $clipId) {
                    throw new ClipRenderValidationException(['editor'=>'Este envio já pertence a outro corte.'], $projectId);
                }
                $this->finish($owned, $savepoint);
                return new ClipRenderReceipt((int)$existing['clip_id'], (int)$existing['project_id'], (int)$existing['render_revision'], false);
            }
            if (!in_array($clip['status'], ['suggested','approved','completed','failed'], true)) {
                throw new ClipRenderValidationException(['editor'=>'Aguarde o processamento deste corte antes de criar outra versão.'], $projectId);
            }
            try {
                if ($prepared===null || $this->sourceDurations===null || $prepared->sourceId()!==(int)$clip['source_id']) {
                    throw new RuntimeException('Precise source measurement is unavailable.');
                }
                $this->sourceDurations->assertCurrent($prepared,$projectId,$userId);
            } catch (Throwable) {
                throw $this->precisionError($projectId);
            }
            if (preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?$/D', $startTime) !== 1
                || preg_match('/^(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?$/D', $endTime) !== 1) {
                throw new ClipRenderValidationException(['end_time'=>'Informe tempos válidos, com até três casas decimais.'], $projectId);
            }
            $start=(float)$startTime;
            $end=(float)$endTime;
            $durationMs=(int)round(($end-$start)*1000);
            if ((int)round($end*1000)>$prepared->milliseconds()) {
                throw new ClipRenderValidationException(['end_time'=>'O fim do corte ultrapassa a duração do vídeo. Limite: '.$prepared->endDecimal().' s.'],$projectId);
            }
            if ($durationMs<1000 || $durationMs>$this->maxDurationSeconds*1000) {
                throw new ClipRenderValidationException(['end_time'=>'O corte deve durar de 1 a '.$this->maxDurationSeconds.' segundos e estar dentro do vídeo.'], $projectId);
            }
            if (!in_array($transcriptMode, ['manual','auto'], true) || $options->style()==='none'
                || ($transcriptMode==='auto' && !(bool)$clip['has_audio'])) {
                throw new ClipRenderValidationException(['subtitles'=>'Escolha legendas manuais ou automáticas com um estilo visível; a transcrição automática exige áudio.'], $projectId);
            }
            try {
                $plan=$this->reframes->validate($reframe, $durationMs);
            } catch (InvalidArgumentException) {
                throw new ClipRenderValidationException(['reframe'=>'Configuração de enquadramento inválida.'], $projectId);
            }
            $transcript=null;
            if ($options->logoAssetId() > 0 && ($this->logoOwned === null || ($this->logoOwned)($options->logoAssetId(),$userId) !== true)) {
                throw new ClipRenderValidationException(['logo_asset_id'=>'Escolha um logo disponível na sua marca.'], $projectId);
            }
            if ($transcriptMode==='manual') {
                try {
                    $transcript=SrtCodec::parse($srt, $durationMs);
                    $originalStart = (float)($clip['render_start_time'] ?? $clip['start_time']);
                    $originalEnd = (float)($clip['render_end_time'] ?? $clip['end_time']);
                    $sameInterval = (int)round($start*1000)===(int)round($originalStart*1000)
                        && (int)round($end*1000)===(int)round($originalEnd*1000);
                    // Owned source and snapshot are read under the owner/project lock.
                    $snapshot = $sameInterval ? $this->editor->snapshot($clipId,(int)$clip['render_revision']) : null;
                    $transcript = TranscriptRevision::merge($transcript,$snapshot['transcript'] ?? null,$sameInterval);
                    if ($transcript->cues()===[]) {
                        throw new InvalidArgumentException('Manual captions cannot be empty.');
                    }
                } catch (InvalidArgumentException) {
                    throw new ClipRenderValidationException(['subtitles'=>'Revise o SRT: ao menos um bloco com texto, tempos válidos dentro do corte e sem sobreposição.'], $projectId);
                }
            }
            $newId=$this->editor->cloneVersion($clipId, $start, $end);
            $revision=1;
            $this->profiles->create($newId, $revision, $plan);
            $this->editor->createProfile($newId, $revision, $clipId, $userId, $requestKey, $options, $transcriptMode, $durationMs);
            if ($transcript!==null) $this->editor->saveTranscript($newId, $revision, $transcript);
            $this->clips->queueRender($newId, $start, $end, $revision);
            $this->jobs->dispatch('generate_subtitles', $projectId, ['clip_id'=>$newId,'render_revision'=>$revision],
                'clip-subtitles:'.$newId.':v'.$revision);
            $this->projects->synchronizeRenderState($projectId);
            $this->finish($owned, $savepoint);
            return new ClipRenderReceipt($newId, $projectId, $revision, true);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                if ($owned) $this->pdo->rollBack();
                else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                }
            }
            throw $error;
        }
    }

    private function finish(bool $owned, string $savepoint): void
    {
        if ($owned) {
            if (!$this->pdo->commit()) throw new RuntimeException('A versão não pôde ser confirmada.');
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        }
    }

    private function precisionError(int $projectId): ClipRenderValidationException
    {
        return new ClipRenderValidationException(['end_time'=>'Não foi possível confirmar a duração precisa do vídeo. Tente novamente.'],$projectId);
    }
}
