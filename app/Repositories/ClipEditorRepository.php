<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\Transcript;
use App\Media\Subtitles\TranscriptValidator;
use PDO;
use RuntimeException;

final class ClipEditorRepository
{
    public function __construct(private PDO $pdo) {}

    public function findOwned(int $clipId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.id,c.project_id,c.ai_analysis_id,c.title,c.status,c.start_time,c.end_time,c.render_start_time,c.render_end_time,c.render_revision,c.render_error_code,
                    s.id AS source_id,s.duration_seconds AS source_duration_seconds,s.width,s.height,s.has_audio,p.name AS project_name
             FROM clips c INNER JOIN projects p ON p.id=c.project_id AND p.user_id=:owner
             INNER JOIN project_sources s ON s.project_id=p.id AND s.status='ready'
             WHERE c.id=:clip AND c.ai_analysis_id=(SELECT MAX(a.id) FROM ai_analyses a WHERE a.project_id=p.id)
               AND s.duration_seconds BETWEEN 1 AND 86400 AND s.object_key IS NOT NULL AND s.mime_type IS NOT NULL LIMIT 1"
        );
        $statement->execute(['owner'=>$userId,'clip'=>$clipId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function ownerId(int $clipId): ?int
    {
        $statement=$this->pdo->prepare('SELECT p.user_id FROM clips c INNER JOIN projects p ON p.id=c.project_id WHERE c.id=?');
        $statement->execute([$clipId]);
        $value=$statement->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    public function lockOwnerAndProject(int $clipId, int $userId): bool
    {
        $this->assertTransaction();
        $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
        $user=$this->pdo->prepare(
            "SELECT u.id FROM users u INNER JOIN plans active_plan ON active_plan.id=u.plan_id AND active_plan.is_active=1
             WHERE u.id=? AND u.status='active'".$suffix
        );
        $user->execute([$userId]);
        if ($user->fetchColumn()===false) return false;
        $project=$this->pdo->prepare('SELECT p.id FROM projects p INNER JOIN clips c ON c.project_id=p.id WHERE c.id=? AND p.user_id=?'.$suffix);
        $project->execute([$clipId,$userId]);
        return $project->fetchColumn()!==false;
    }

    public function findRequest(int $userId, string $key): ?array
    {
        $statement=$this->pdo->prepare('SELECT e.clip_id,e.render_revision,e.parent_clip_id,c.project_id FROM clip_editor_profiles e INNER JOIN clips c ON c.id=e.clip_id WHERE e.user_id=? AND e.request_key=?');
        $statement->execute([$userId,$key]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function cloneVersion(int $clipId, float $start, float $end): int
    {
        $this->assertTransaction();
        $statement=$this->pdo->prepare(
            "INSERT INTO clips (project_id,ai_analysis_id,suggestion_index,title,start_time,end_time,duration_seconds,viral_score,hook,reason,category,status)
             SELECT project_id,ai_analysis_id,NULL,title,?,?,?,viral_score,hook,reason,category,'suggested' FROM clips WHERE id=?"
        );
        $statement->execute([number_format($start,3,'.',''),number_format($end,3,'.',''),number_format($end-$start,3,'.',''),$clipId]);
        if ($statement->rowCount()!==1) throw new RuntimeException('A versão não pôde ser criada.');
        return (int)$this->pdo->lastInsertId();
    }

    public function createProfile(int $clipId, int $revision, ?int $parentId, int $userId, string $requestKey, EditorOptions $options, string $mode, int $durationMs): void
    {
        $this->assertTransaction();
        $this->pdo->prepare('INSERT INTO clip_editor_profiles (clip_id,render_revision,parent_clip_id,user_id,request_key,options_json,transcript_mode,duration_ms) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$clipId,$revision,$parentId,$userId,$requestKey,json_encode($options->toArray(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$mode,$durationMs]);
        $profileId=(int)$this->pdo->lastInsertId();
        if ($mode!=='none') {
            $this->pdo->prepare('INSERT INTO clip_subtitle_tracks (editor_profile_id) VALUES (?)')->execute([$profileId]);
        }
    }

    /** @return array{options:EditorOptions,transcript:?Transcript,track_status:?string,mode:string,duration_ms:int,parent_clip_id:?int}|null */
    public function snapshot(int $clipId, int $revision): ?array
    {
        $statement=$this->pdo->prepare('SELECT e.options_json,e.transcript_mode,e.duration_ms,e.parent_clip_id,t.id AS track_id,t.status,t.language FROM clip_editor_profiles e LEFT JOIN clip_subtitle_tracks t ON t.editor_profile_id=e.id WHERE e.clip_id=? AND e.render_revision=?');
        $statement->execute([$clipId,$revision]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $transcript=null;
        if ($row['status']==='ready') {
            $cues=$this->pdo->prepare('SELECT start_ms,end_ms,text,words_json FROM clip_subtitle_cues WHERE track_id=? ORDER BY cue_index');
            $cues->execute([(int)$row['track_id']]);
            $items=[];
            foreach ($cues->fetchAll(PDO::FETCH_ASSOC) as $cue) {
                $item=['start_ms'=>(int)$cue['start_ms'],'end_ms'=>(int)$cue['end_ms'],'text'=>(string)$cue['text']];
                if ($cue['words_json']!==null) $item['words']=json_decode($cue['words_json'],true,64,JSON_THROW_ON_ERROR);
                $items[]=$item;
            }
            $transcript=TranscriptValidator::fromArray(['language'=>$row['language'],'cues'=>$items],(int)$row['duration_ms']);
        }
        return ['options'=>EditorOptions::fromArray(json_decode($row['options_json'],true,64,JSON_THROW_ON_ERROR)),
            'transcript'=>$transcript,'track_status'=>$row['status'],'mode'=>$row['transcript_mode'],'duration_ms'=>(int)$row['duration_ms'],
            'parent_clip_id'=>$row['parent_clip_id']===null ? null : (int)$row['parent_clip_id']];
    }

    public function saveTranscript(int $clipId, int $revision, Transcript $transcript): void
    {
        $this->assertTransaction();
        if ($transcript->cues() === []) {
            throw new RuntimeException('Uma transcrição vazia não pode ser marcada como pronta.');
        }
        $suffix=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
        $statement=$this->pdo->prepare('SELECT t.id,t.status,e.duration_ms FROM clip_subtitle_tracks t INNER JOIN clip_editor_profiles e ON e.id=t.editor_profile_id WHERE e.clip_id=? AND e.render_revision=?'.$suffix);
        $statement->execute([$clipId,$revision]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int)$row['duration_ms']!==$transcript->durationMs()) throw new RuntimeException('A transcrição não corresponde à versão.');
        if ($row['status']==='ready') {
            $existing=$this->snapshot($clipId,$revision);
            if ($existing['transcript']->toArray()!==$transcript->toArray()) throw new RuntimeException('A versão de legenda já está concluída.');
            return;
        }
        if ($row['status']!=='pending') throw new RuntimeException('A versão de legenda não está pendente.');
        $insert=$this->pdo->prepare('INSERT INTO clip_subtitle_cues (track_id,cue_index,start_ms,end_ms,text,words_json) VALUES (?,?,?,?,?,?)');
        foreach ($transcript->cues() as $index=>$cue) {
            $insert->execute([(int)$row['id'],$index,$cue->startMs(),$cue->endMs(),$cue->text(),
                $cue->words()===[] ? null : json_encode($cue->words(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        }
        $this->pdo->prepare("UPDATE clip_subtitle_tracks SET status='ready',language=?,error_code=NULL,completed_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$transcript->language(),(int)$row['id']]);
    }

    public function markSubtitlesFailed(int $clipId, int $revision, string $code): void
    {
        $this->assertTransaction();
        $this->pdo->prepare("UPDATE clip_subtitle_tracks SET status='failed',error_code=? WHERE status='pending' AND editor_profile_id IN (SELECT id FROM clip_editor_profiles WHERE clip_id=? AND render_revision=?)")
            ->execute([$code,$clipId,$revision]);
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('A operação de edição exige transação.');
    }
}
