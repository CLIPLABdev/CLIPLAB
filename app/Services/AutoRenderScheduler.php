<?php

declare(strict_types=1);

namespace App\Services;

use App\Media\PreciseSourceDuration;
use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;

final class AutoRenderScheduler
{
    private Closure $requestRender;

    /** @param callable(int,int,string,string,PreciseSourceDuration):mixed $requestRender */
    public function __construct(private PDO $pdo, callable $requestRender, private int $maximumClips = 3, private ?SourceDurationPreflight $sourceDurations = null)
    {
        if ($maximumClips < 1 || $maximumClips > 3) throw new InvalidArgumentException('Automatic export limit is invalid.');
        $this->requestRender = Closure::fromCallable($requestRender);
    }

    public function prepare(int $analysisId, int $projectId, int $userId): ?PreciseSourceDuration
    {
        if ($this->pdo->inTransaction()) throw new LogicException('Automatic export preflight requires no transaction.');
        foreach ($this->candidates($analysisId,$projectId,$userId,false) as $clip) {
            if ($clip['status'] !== 'suggested' || (int)$clip['render_revision'] !== 0) continue;
            if ($this->sourceDurations === null) throw new \RuntimeException('Precise source measurement is unavailable.');
            $snapshot=$this->sourceDurations->prepare($projectId,$userId);
            if ($snapshot===null) throw new \RuntimeException('Precise source measurement is unavailable.');
            return $snapshot;
        }
        return null;
    }

    public function schedule(int $analysisId, int $projectId, int $userId, ?PreciseSourceDuration $prepared = null): int
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('Automatic exports require the analysis transaction.');
        $queued=0;
        foreach ($this->candidates($analysisId,$projectId,$userId,true) as $clip) {
            if ($clip['status'] !== 'suggested' || (int)$clip['render_revision'] !== 0) continue;
            if ($prepared===null || $this->sourceDurations===null) throw new \RuntimeException('Precise source measurement is unavailable.');
            $this->sourceDurations->assertCurrent($prepared,$projectId,$userId);
            $endMs=min((int)round((float)$clip['end_time']*1000),$prepared->milliseconds());
            $end=number_format($endMs/1000,3,'.','');
            ($this->requestRender)((int)$clip['id'],$userId,(string)$clip['start_time'],$end,$prepared);
            ++$queued;
        }
        return $queued;
    }

    /** @return list<array<string,mixed>> */
    private function candidates(int $analysisId,int $projectId,int $userId,bool $forUpdate):array
    {
        $suffix = $forUpdate && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $project = $this->pdo->prepare(
            'SELECT id FROM projects WHERE id=:project AND user_id=:owner AND auto_render_requested=1
             AND :analysis=(SELECT MAX(a.id) FROM ai_analyses a WHERE a.project_id=:analysis_project)' . $suffix
        );
        foreach (['project'=>$projectId,'owner'=>$userId,'analysis'=>$analysisId,'analysis_project'=>$projectId] as $key=>$value) {
            $project->bindValue(':'.$key,$value,PDO::PARAM_INT);
        }
        $project->execute();
        if ($project->fetchColumn() === false) return [];
        // Select the original top N, including already requested exports. Replays must
        // not advance to the next N and accidentally export every suggestion.
        $clips = $this->pdo->prepare(
            'SELECT id,status,render_revision,start_time,end_time FROM clips
             WHERE project_id=:project AND ai_analysis_id=:analysis AND suggestion_index IS NOT NULL
             ORDER BY viral_score DESC,suggestion_index ASC LIMIT :maximum' . $suffix
        );
        $clips->bindValue(':project',$projectId,PDO::PARAM_INT);
        $clips->bindValue(':analysis',$analysisId,PDO::PARAM_INT);
        $clips->bindValue(':maximum',$this->maximumClips,PDO::PARAM_INT);
        $clips->execute();
        return $clips->fetchAll(PDO::FETCH_ASSOC);
    }
}
