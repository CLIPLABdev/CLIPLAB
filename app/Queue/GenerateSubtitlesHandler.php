<?php

declare(strict_types=1);

namespace App\Queue;

use App\Contracts\ClipAudioExtractor;
use App\Contracts\TimedTranscriptionProvider;
use App\Contracts\JobDispatcher;
use App\Exceptions\SubtitleException;
use App\Gemini\GeminiException;
use App\Media\ProjectSource;
use App\Media\Subtitles\Transcript;
use App\Media\Editor\EditorOptions;
use Throwable;

final class GenerateSubtitlesHandler implements JobHandler
{
    public function __construct(
        private object $editor,
        private object $clips,
        private object $projects,
        private ClipAudioExtractor $audio,
        private TimedTranscriptionProvider $provider,
        private ProcessingEffectGuard $effects,
        private JobDispatcher $jobs
    ) {}

    public function handle(ClaimedJob $job): JobOutcome
    {
        $payload=$job->payload();
        if (count($payload)!==2 || !isset($payload['clip_id'],$payload['render_revision'])
            || !is_int($payload['clip_id']) || !is_int($payload['render_revision'])
            || $payload['clip_id']<1 || $payload['render_revision']<1) {
            return $this->failure('subtitle_failed');
        }
        $id=$payload['clip_id'];
        $revision=$payload['render_revision'];
        try {
            $context=$this->clips->findForRenderJob($id,$revision);
            if ($context===null) return JobOutcome::completed();
            if (($context['id']??null)!==$id || ($context['project_id']??null)!==$job->projectId()
                || ($context['render_revision']??null)!==$revision || !($context['source']??null) instanceof ProjectSource) {
                return $this->failure('subtitle_failed');
            }
            $snapshot=$this->editor->snapshot($id,$revision);
            if (!is_array($snapshot)) return $this->terminal($job,$id,$revision,'subtitle_failed');
            $mode=$snapshot['mode'] ?? null;
            $trackStatus=$snapshot['track_status'] ?? null;
            $durationMs=$snapshot['duration_ms'] ?? null;
            $options=$snapshot['options'] ?? null;
            if (!in_array($mode,['auto','manual'],true) || !in_array($trackStatus,['pending','ready','failed'],true)
                || !$options instanceof EditorOptions || $options->style()==='none' || !is_int($durationMs)) {
                return $this->terminal($job,$id,$revision,'subtitle_failed');
            }
            if ($trackStatus==='failed' || ($mode==='manual' && $trackStatus!=='ready')) {
                return $this->terminal($job,$id,$revision,'subtitle_failed');
            }
            $duration=$context['render_end_time']-$context['render_start_time'];
            if (!is_finite($duration) || $duration<1 || $duration>180 || (int)round($duration*1000)!==$durationMs) {
                return $this->terminal($job,$id,$revision,'subtitle_failed');
            }
            if (!$this->effects->apply($job,static function (): void {})) return JobOutcome::deferred(15);
        } catch (\PDOException) {
            return JobOutcome::deferred(15);
        } catch (\JsonException | \InvalidArgumentException | \TypeError) {
            return $this->terminal($job,$id,$revision,'subtitle_failed');
        } catch (Throwable) {
            return JobOutcome::deferred(15);
        }

        $transcript=$snapshot['transcript'] ?? null;
        $persistTranscript=$mode==='auto' && $trackStatus!=='ready';
        if ($persistTranscript) {
            try {
                $wav=$this->audio->extract($context['source'],$context['render_start_time'],$duration);
                $transcript=$this->provider->transcribe($wav,$snapshot['duration_ms']);
                unset($wav);
            } catch (GeminiException $error) {
                if ($error->isTransient() && $job->attempts()<$job->maxAttempts()) {
                    return JobOutcome::retry($error->publicCode(),ProcessingErrorCatalog::requireMessage($error->publicCode()));
                }
                return $this->terminal($job,$id,$revision,$error->publicCode());
            } catch (SubtitleException $error) {
                if ($error->publicCode()==='subtitle_unavailable' && $job->attempts()<$job->maxAttempts()) {
                    return JobOutcome::retry(
                        $error->publicCode(),
                        ProcessingErrorCatalog::requireMessage($error->publicCode())
                    );
                }
                return $this->terminal($job,$id,$revision,$error->publicCode());
            } catch (Throwable) {
                if ($job->attempts()<$job->maxAttempts()) return JobOutcome::retry('subtitle_failed',ProcessingErrorCatalog::requireMessage('subtitle_failed'));
                return $this->terminal($job,$id,$revision,'subtitle_failed');
            }
        }
        if (!$transcript instanceof Transcript || $transcript->durationMs()!==$durationMs) {
            return $this->terminal($job,$id,$revision,'subtitle_failed');
        }
        if ($transcript->cues()===[]) return $this->terminal($job,$id,$revision,'subtitle_empty');
        try {
            $applied=$this->effects->apply($job,function () use ($job,$id,$revision,$transcript,$persistTranscript): void {
                // Recheck current analysis/revision after the external call, while the lease lock is held.
                $current=$this->clips->findForRenderJob($id,$revision);
                if ($current===null || $current['project_id']!==$job->projectId()) return;
                if ($persistTranscript) $this->editor->saveTranscript($id,$revision,$transcript);
                $this->jobs->dispatch('render_clip',$job->projectId(),['clip_id'=>$id,'render_revision'=>$revision],'clip-render:'.$id.':v'.$revision);
                $this->projects->synchronizeRenderState($job->projectId());
            });
            return $applied ? JobOutcome::completed() : JobOutcome::deferred(15);
        } catch (\PDOException) {
            return JobOutcome::deferred(15);
        } catch (Throwable) {
            if ($job->attempts()<$job->maxAttempts()) {
                return JobOutcome::retry('subtitle_failed',ProcessingErrorCatalog::requireMessage('subtitle_failed'));
            }
        }
            return $this->terminal($job,$id,$revision,'subtitle_failed');
    }

    private function terminal(ClaimedJob $job,int $id,int $revision,string $code): JobOutcome
    {
        try {
            $applied=$this->effects->apply($job,function () use ($job,$id,$revision,$code): void {
                if ($this->clips->findForRenderJob($id,$revision)===null) return;
                $this->editor->markSubtitlesFailed($id,$revision,$code);
                $this->clips->markRenderFailed($id,$revision,$code);
                $this->projects->synchronizeRenderState($job->projectId());
            });
            return $applied ? $this->failure($code) : JobOutcome::deferred(15);
        } catch (Throwable) {
            return JobOutcome::deferred(15);
        }
    }

    private function failure(string $code): JobOutcome
    {
        return JobOutcome::failed($code,ProcessingErrorCatalog::requireMessage($code));
    }
}
