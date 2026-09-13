<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ClipRenderProfileStore;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use App\Media\Reframe\ReframePlanResolution;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class ClipRenderProfileRepository implements ClipRenderProfileStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(int $clipId, int $renderRevision, ReframePlan $plan): int
    {
        $this->assertIdentifiers($clipId, $renderRevision);
        $aspectRatio = $plan->aspectRatio();
        $statement = $this->pdo->prepare(
            'INSERT INTO clip_render_profiles '
            . '(clip_id, render_revision, aspect_ratio, reframe_mode, output_width, output_height, detector_version) '
            . 'VALUES (:clip_id, :render_revision, :aspect_ratio, :reframe_mode, :output_width, :output_height, :detector_version)'
        );
        $statement->execute([
            'clip_id' => $clipId,
            'render_revision' => $renderRevision,
            'aspect_ratio' => $aspectRatio->value(),
            'reframe_mode' => $plan->mode(),
            'output_width' => $aspectRatio->outputWidth(),
            'output_height' => $aspectRatio->outputHeight(),
            'detector_version' => $plan->detectorVersion(),
        ]);
        $profileId = (int) $this->pdo->lastInsertId();

        $insertKeyframe = $this->pdo->prepare(
            'INSERT INTO clip_reframe_keyframes '
            . '(render_profile_id, sequence_index, at_ms, center_x, center_y, source) '
            . 'VALUES (:render_profile_id, :sequence_index, :at_ms, :center_x, :center_y, :source)'
        );
        foreach ($plan->keyframes() as $sequence => $keyframe) {
            $insertKeyframe->execute([
                'render_profile_id' => $profileId,
                'sequence_index' => $sequence,
                'at_ms' => $keyframe->atMs(),
                'center_x' => $keyframe->centerXDecimal(),
                'center_y' => $keyframe->centerYDecimal(),
                'source' => $keyframe->source(),
            ]);
        }

        return $profileId;
    }

    public function findForClipRevision(int $clipId, int $renderRevision): ?ReframePlan
    {
        $this->assertIdentifiers($clipId, $renderRevision);
        $statement = $this->pdo->prepare(
            'SELECT id, aspect_ratio, reframe_mode, output_width, output_height, detector_version '
            . 'FROM clip_render_profiles WHERE clip_id = :clip_id AND render_revision = :render_revision LIMIT 1'
        );
        $statement->execute(['clip_id' => $clipId, 'render_revision' => $renderRevision]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile)) {
            return null;
        }

        try {
            $keyframes = $this->keyframes((int) $profile['id']);
            $aspectRatio = AspectRatio::fromStored((string) $profile['aspect_ratio'],
                $profile['output_width'] === null ? null : (int)$profile['output_width'],
                $profile['output_height'] === null ? null : (int)$profile['output_height']);
            $this->assertDimensions($profile, $aspectRatio);
            $mode = (string) $profile['reframe_mode'];
            $detectorVersion = $profile['detector_version'] === null ? null : (string) $profile['detector_version'];

            if ($mode === 'original' && $aspectRatio->isOriginal() && $detectorVersion === null && $keyframes === []) {
                return ReframePlan::original();
            }
            if ($mode === 'center' && !$aspectRatio->isOriginal() && $detectorVersion === null && $keyframes === []) {
                return ReframePlan::center($aspectRatio);
            }
            if ($mode === 'manual' && !$aspectRatio->isOriginal() && $detectorVersion === null && count($keyframes) === 1) {
                return ReframePlan::manual($aspectRatio, $keyframes[0]);
            }
            if ($mode === 'auto' && !$aspectRatio->isOriginal() && is_string($detectorVersion)) {
                return ReframePlan::automatic($aspectRatio, $detectorVersion, $keyframes);
            }
        } catch (InvalidArgumentException) {
            throw new RuntimeException('Stored reframe profile is inconsistent.');
        }

        throw new RuntimeException('Stored reframe profile is inconsistent.');
    }

    public function hasForClip(int $clipId): bool
    {
        $this->assertPositive($clipId, 'Clip identifier is invalid.');
        $statement = $this->pdo->prepare('SELECT 1 FROM clip_render_profiles WHERE clip_id = :clip_id LIMIT 1');
        $statement->execute(['clip_id' => $clipId]);

        return $statement->fetchColumn() !== false;
    }

    public function resolveForJob(int $clipId, int $renderRevision): ReframePlanResolution
    {
        $plan = $this->findForClipRevision($clipId, $renderRevision);
        if ($plan !== null) {
            return ReframePlanResolution::matched($plan);
        }

        return $this->hasForClip($clipId) ? ReframePlanResolution::mismatch() : ReframePlanResolution::legacy();
    }

    /** @return list<ReframeKeyframe> */
    private function keyframes(int $profileId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sequence_index, at_ms, center_x, center_y, source FROM clip_reframe_keyframes '
            . 'WHERE render_profile_id = :render_profile_id ORDER BY sequence_index ASC'
        );
        $statement->execute(['render_profile_id' => $profileId]);
        $keyframes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['sequence_index'] !== count($keyframes)) {
                throw new RuntimeException('Stored reframe profile is inconsistent.');
            }
            $keyframes[] = new ReframeKeyframe(
                (int) $row['at_ms'],
                (float) $row['center_x'],
                (float) $row['center_y'],
                (string) $row['source']
            );
        }

        return $keyframes;
    }

    /** @param array<string,mixed> $profile */
    private function assertDimensions(array $profile, AspectRatio $aspectRatio): void
    {
        $width = $profile['output_width'] === null ? null : (int) $profile['output_width'];
        $height = $profile['output_height'] === null ? null : (int) $profile['output_height'];
        if ($width !== $aspectRatio->outputWidth() || $height !== $aspectRatio->outputHeight()) {
            throw new RuntimeException('Stored reframe profile is inconsistent.');
        }
    }

    private function assertIdentifiers(int $clipId, int $renderRevision): void
    {
        $this->assertPositive($clipId, 'Clip identifier is invalid.');
        $this->assertPositive($renderRevision, 'Render revision is invalid.');
    }

    private function assertPositive(int $value, string $message): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException($message);
        }
    }
}
