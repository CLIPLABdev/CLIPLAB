<?php

declare(strict_types=1);

namespace App\Queue;

use App\Contracts\ClipRenderer;
use App\Contracts\ClipRenderProfileStore;
use App\Contracts\PrivateStorage;
use App\Contracts\RenderArtifactCleanupStore;
use App\Exceptions\ClipRenderException;
use App\Media\ProjectSource;
use App\Media\Reframe\ReframePlan;
use App\Media\RenderClipRequest;
use App\Media\RenderedClipArtifacts;
use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\Transcript;
use App\Media\StoredObject;
use App\Plans\PlanLimitExceeded;
use InvalidArgumentException;
use Throwable;

final class RenderClipHandler implements JobHandler
{
    private const LEASE_DEFER_SECONDS = 15;
    private const UNAVAILABLE_DEFER_SECONDS = 300;
    private ?\Closure $publishQuotaGuard;

    public function __construct(
        private object $clips,
        private object $projects,
        private ClipRenderer $renderer,
        private PrivateStorage $storage,
        private ProcessingEffectGuard $effects,
        private int $videoMaxBytes,
        private int $thumbnailMaxBytes,
        private RenderArtifactCleanupStore $cleanups,
        private ClipRenderProfileStore $profiles,
        private int $maxDurationSeconds = 180,
        private ?object $editor = null,
        ?callable $publishQuotaGuard = null
    ) {
        if ($videoMaxBytes < 1 || $thumbnailMaxBytes < 1
            || $maxDurationSeconds < 1 || $maxDurationSeconds > 180
        ) {
            throw new InvalidArgumentException('Render storage limits must be positive.');
        }
        $this->publishQuotaGuard = $publishQuotaGuard === null ? null : \Closure::fromCallable($publishQuotaGuard);
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        $this->recoverPendingCleanups();

        $payload = $this->exactPayload($job->payload());
        if ($payload === null) {
            return $this->failedOutcome('render_failed');
        }

        try {
            $context = $this->clips->findForRenderJob($payload['clip_id'], $payload['render_revision']);
        } catch (Throwable) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if ($context === null) {
            return JobOutcome::completed();
        }
        if (!$this->validContext($context, $job, $payload)) {
            return $this->failedOutcome('render_failed');
        }

        try {
            $resolution = $this->profiles->resolveForJob(
                $payload['clip_id'],
                $payload['render_revision']
            );
        } catch (Throwable) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if ($resolution->state() === 'legacy') {
            $plan = ReframePlan::original();
        } elseif ($resolution->state() === 'matched' && $resolution->plan() instanceof ReframePlan) {
            $plan = $resolution->plan();
        } else {
            return $this->invalidProfileOutcome($job, $payload);
        }

        $editorSnapshot = null;
        if ($this->editor === null) {
            return $this->captionFailureOutcome($job, $payload);
        }
        try {
            $editorSnapshot = $this->editor->snapshot($payload['clip_id'], $payload['render_revision']);
        } catch (\PDOException) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->captionFailureOutcome($job, $payload);
        }
        $captionState = $this->captionState($editorSnapshot, (int) round(($context['render_end_time'] - $context['render_start_time']) * 1000));
        if ($captionState === 'pending') {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if ($captionState !== 'ready') {
            return $this->captionFailureOutcome($job, $payload);
        }
        try {
            $applied = $this->effects->apply($job, function () use ($payload): void {
                $this->clips->markRendering($payload['clip_id'], $payload['render_revision']);
            });
        } catch (Throwable) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if (!$applied) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }

        $artifacts = null;
        try {
            try {
                $artifacts = $this->renderer->render(new RenderClipRequest(
                    $context['source'],
                    $context['render_start_time'],
                    $context['render_end_time'] - $context['render_start_time'],
                    bin2hex(random_bytes(16)),
                    $plan,
                    $editorSnapshot['options'] ?? null,
                    $editorSnapshot['transcript'] ?? null
                ));
            } catch (ClipRenderException $exception) {
                return $this->failureOutcome($job, $payload, $exception->publicCode());
            } catch (Throwable) {
                return $this->failureOutcome($job, $payload, 'render_failed');
            }

            return $this->publish($job, $payload, $artifacts);
        } finally {
            if ($artifacts instanceof RenderedClipArtifacts) {
                $artifacts->cleanup();
            }
        }
    }
    /** @param array{clip_id:int,render_revision:int} $payload */
    private function captionFailureOutcome(ClaimedJob $job, array $payload): JobOutcome
    {
        try {
            $applied = $this->effects->apply($job, function () use ($job, $payload): void {
                if ($this->editor !== null && method_exists($this->editor, 'markSubtitlesFailed')) {
                    $this->editor->markSubtitlesFailed($payload['clip_id'], $payload['render_revision'], 'subtitle_failed');
                }
                $this->clips->markRenderFailed($payload['clip_id'], $payload['render_revision'], 'subtitle_failed');
                $this->projects->synchronizeRenderState($job->projectId());
            });
        } catch (Throwable) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }

        return $applied ? $this->failedOutcome('subtitle_failed') : JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
    }

    /** @param array<string,mixed>|null $snapshot */
    private function captionState(?array $snapshot, int $durationMs): string
    {
        if ($snapshot === null
            || !in_array($snapshot['mode'] ?? null, ['auto', 'manual'], true)
            || !($snapshot['options'] ?? null) instanceof EditorOptions
            || $snapshot['options']->style() === 'none'
            || !is_int($snapshot['duration_ms'] ?? null)
            || $snapshot['duration_ms'] !== $durationMs
        ) {
            return 'invalid';
        }

        $status = $snapshot['track_status'] ?? null;
        $transcript = $snapshot['transcript'] ?? null;
        if ($status === 'pending') {
            return $snapshot['mode'] === 'auto' && $transcript === null ? 'pending' : 'invalid';
        }
        if ($status !== 'ready' || !$transcript instanceof Transcript
            || $transcript->durationMs() !== $durationMs || $transcript->cues() === []) {
            return 'invalid';
        }

        return 'ready';
    }


    /** @param array{clip_id:int,render_revision:int} $payload */
    private function invalidProfileOutcome(ClaimedJob $job, array $payload): JobOutcome
    {
        try {
            $applied = $this->effects->apply($job, function () use ($job, $payload): void {
                $this->clips->markRenderFailed(
                    $payload['clip_id'],
                    $payload['render_revision'],
                    'render_output_invalid'
                );
                $this->projects->synchronizeRenderState($job->projectId());
            });
        } catch (Throwable) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if (!$applied) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }

        return $this->failedOutcome('render_output_invalid');
    }

    /** @param array{clip_id:int,render_revision:int} $payload */
    private function publish(ClaimedJob $job, array $payload, RenderedClipArtifacts $artifacts): JobOutcome
    {
        $nonce = bin2hex(random_bytes(16));
        $videoKey = sprintf('processed/%d/%d-%s.mp4', $job->projectId(), $payload['clip_id'], $nonce);
        $thumbnailKey = sprintf('thumbnails/%d/%d-%s.jpg', $job->projectId(), $payload['clip_id'], $nonce);
        $reservedKeys = [$videoKey, $thumbnailKey];
        $published = [];

        try {
            $reserved = $this->cleanups->reserve($job, $reservedKeys);
        } catch (Throwable) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if (!$reserved) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }

        try {
            $video = $this->store($artifacts->videoPath(), $videoKey, $this->videoMaxBytes);
            $published[] = $video->objectKey();
            $thumbnail = $this->store($artifacts->thumbnailPath(), $thumbnailKey, $this->thumbnailMaxBytes);
            $published[] = $thumbnail->objectKey();
        } catch (Throwable) {
            $this->markForCleanup($job, $reservedKeys);
            $this->deleteObjects($published);

            return $this->failureOutcome($job, $payload, 'render_storage_failed');
        }

        try {
            $applied = $this->effects->apply($job, function () use ($job, $payload, $video, $thumbnail, $reservedKeys): void {
                if ($this->publishQuotaGuard !== null) {
                    ($this->publishQuotaGuard)($payload['clip_id'], $video->sizeBytes() + $thumbnail->sizeBytes());
                }
                $this->clips->completeRender($payload['clip_id'], $payload['render_revision'], $video, $thumbnail);
                $this->projects->synchronizeRenderState($job->projectId());
                $this->cleanups->release($job, $reservedKeys);
            });
        } catch (PlanLimitExceeded $error) {
            $this->markForCleanup($job, $reservedKeys);
            $this->deleteObjects($reservedKeys);
            return $this->failureOutcome($job, $payload, $error->errorCode(), false);
        } catch (Throwable) {
            $this->markForCleanup($job, $reservedKeys);
            $this->deleteObjects($reservedKeys);

            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if (!$applied) {
            $this->markForCleanup($job, $reservedKeys);
            $this->deleteObjects($reservedKeys);

            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }

        return JobOutcome::completed();
    }

    private function store(string $path, string $key, int $maxBytes): StoredObject
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Rendered artifact could not be opened.');
        }
        try {
            $stored = $this->storage->putStream($stream, $key, $maxBytes);
        } finally {
            fclose($stream);
        }
        if ($stored->objectKey() !== $key || $stored->sizeBytes() < 1 || $stored->sizeBytes() > $maxBytes) {
            $this->deleteObjects([$stored->objectKey()]);
            throw new \RuntimeException('Stored render artifact is invalid.');
        }

        return $stored;
    }

    /** @param array{clip_id:int,render_revision:int} $payload */
    private function failureOutcome(ClaimedJob $job, array $payload, string $code, bool $retryable = true): JobOutcome
    {
        if ($code === 'render_unavailable') {
            if (!$this->applyQueued($job, $payload)) {
                return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
            }

            return JobOutcome::deferred(self::UNAVAILABLE_DEFER_SECONDS);
        }
        if (ProcessingErrorCatalog::message($code) === null) {
            $code = 'render_failed';
        }
        if ($retryable && $job->attempts() < $job->maxAttempts()) {
            if (!$this->applyQueued($job, $payload)) {
                return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
            }

            return JobOutcome::retry($code, ProcessingErrorCatalog::requireMessage($code));
        }

        try {
            $applied = $this->effects->apply($job, function () use ($job, $payload, $code): void {
                $this->clips->markRenderFailed($payload['clip_id'], $payload['render_revision'], $code);
                $this->projects->synchronizeRenderState($job->projectId());
            });
        } catch (Throwable) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }
        if (!$applied) {
            return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
        }

        return $this->failedOutcome($code);
    }

    /** @param array{clip_id:int,render_revision:int} $payload */
    private function applyQueued(ClaimedJob $job, array $payload): bool
    {
        try {
            return $this->effects->apply($job, function () use ($payload): void {
                $this->clips->markRenderQueued($payload['clip_id'], $payload['render_revision']);
            });
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $payload @return array{clip_id:int,render_revision:int}|null */
    private function exactPayload(array $payload): ?array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['clip_id', 'render_revision']) {
            return null;
        }
        if (!is_int($payload['clip_id']) || $payload['clip_id'] < 1
            || !is_int($payload['render_revision']) || $payload['render_revision'] < 1
        ) {
            return null;
        }

        return ['clip_id' => $payload['clip_id'], 'render_revision' => $payload['render_revision']];
    }

    /** @param array<string,mixed> $context @param array{clip_id:int,render_revision:int} $payload */
    private function validContext(array $context, ClaimedJob $job, array $payload): bool
    {
        $start = $context['render_start_time'] ?? null;
        $end = $context['render_end_time'] ?? null;

        return ($context['id'] ?? null) === $payload['clip_id']
            && ($context['project_id'] ?? null) === $job->projectId()
            && in_array($context['status'] ?? null, ['queued', 'rendering'], true)
            && ($context['render_revision'] ?? null) === $payload['render_revision']
            && is_float($start) && is_finite($start) && $start >= 0.0
            && is_float($end) && is_finite($end) && $end > $start
            && $end - $start >= 1.0 && $end - $start <= (float) $this->maxDurationSeconds
            && ($context['source'] ?? null) instanceof ProjectSource
            && $context['source']->projectId() === $job->projectId();
    }

    /** @param list<string> $keys */
    private function deleteObjects(array $keys): void
    {
        foreach (array_values(array_unique($keys)) as $key) {
            try {
                $this->storage->delete($key);
            } catch (Throwable) {
                continue;
            }
            try {
                $this->cleanups->forget($key);
            } catch (Throwable) {
                // A retained write-ahead reservation makes the idempotent delete retryable.
            }
        }
    }

    /** @param list<string> $keys */
    private function markForCleanup(ClaimedJob $job, array $keys): void
    {
        try {
            $this->cleanups->markForCleanup($job, $keys);
        } catch (Throwable) {
            // The write-ahead reservation remains durable and becomes eligible when its lease expires.
        }
    }

    private function recoverPendingCleanups(): void
    {
        try {
            $keys = $this->cleanups->pending();
        } catch (Throwable) {
            return;
        }
        foreach ($keys as $key) {
            try {
                $this->storage->delete($key);
                $this->cleanups->forget($key);
            } catch (Throwable) {
                // Pending rows remain durable for a later handler invocation.
            }
        }
    }

    private function failedOutcome(string $code): JobOutcome
    {
        return JobOutcome::failed($code, ProcessingErrorCatalog::requireMessage($code));
    }
}
