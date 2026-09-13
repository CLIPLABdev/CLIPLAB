<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ClipSubtitleStatusRepository
{
    public function __construct(private PDO $pdo) {}

    public function forOwnedClip(int $clipId, int $userId): ?string
    {
        $query = $this->pdo->prepare(
            "SELECT e.render_revision, c.project_id FROM clip_editor_profiles e
             INNER JOIN clips c ON c.id=e.clip_id AND c.render_revision=e.render_revision
             INNER JOIN projects p ON p.id=c.project_id AND p.user_id=?
             INNER JOIN clip_subtitle_tracks t ON t.editor_profile_id=e.id AND t.status='pending'
             WHERE c.id=? AND c.status='queued' AND e.transcript_mode='auto'
               AND c.ai_analysis_id=(SELECT MAX(a.id) FROM ai_analyses a WHERE a.project_id=p.id)"
        );
        $query->execute([$userId, $clipId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'UTC_TIMESTAMP()' : "datetime('now')";
        $job = $this->pdo->prepare("SELECT COUNT(*) FROM processing_jobs WHERE project_id=? AND idempotency_key=? AND type='generate_subtitles' AND status='running' AND leased_until >= " . $now);
        $job->execute([(int) $row['project_id'], hash('sha256', 'clip-subtitles:' . $clipId . ':v' . (int) $row['render_revision'])]);

        return (int) $job->fetchColumn() > 0 ? 'processing' : 'queued';
    }
}
