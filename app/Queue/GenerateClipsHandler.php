<?php

declare(strict_types=1);

namespace App\Queue;

use App\Ai\AnalysisResponseValidator;
use App\Ai\InvalidAnalysisResponse;
use App\Credits\CreditReservation;
use PDOException;
use RuntimeException;
use Throwable;

final class GenerateClipsHandler implements JobHandler
{
    private const CHECKPOINT_DEFER_SECONDS = 15;
    private ?\Closure $automaticExports;
    private ?\Closure $automaticExportPreflight;

    public function __construct(
        private object $analyses,
        private object $clips,
        private object $projects,
        private object $reservations,
        private object $credits,
        private AnalysisResponseValidator $validator,
        private ProcessingEffectGuard $effects,
        ?callable $automaticExports = null,
        ?callable $automaticExportPreflight = null
    ) {
        $this->automaticExports = $automaticExports === null ? null : \Closure::fromCallable($automaticExports);
        $this->automaticExportPreflight = $automaticExportPreflight === null ? null : \Closure::fromCallable($automaticExportPreflight);
    }

    public function handle(ClaimedJob $job): JobOutcome
    {
        $payload = $this->exactPayload($job->payload());
        if ($payload === null) {
            return $this->failedOutcome('analysis_not_found');
        }
        try {
            $context = $this->context($job, $payload);
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->failedOutcome('analysis_not_found');
        }
        if ($context === null) {
            return $this->failedOutcome('analysis_not_found');
        }
        if (!$context['generic_reservation'] instanceof CreditReservation
            || $context['generic_reservation']->projectId() !== $job->projectId()
            || $context['generic_reservation']->userId() !== $context['owner_id']
        ) {
            return $this->failWithoutRefund($job, $context, 'analysis_not_found');
        }
        $analysisStatus = (string) $context['analysis']['status'];
        $reservationStatus = $context['reservation'] instanceof CreditReservation
            ? $context['reservation']->status()
            : null;
        if (!$context['reservation'] instanceof CreditReservation) {
            return $this->failWithoutRefund($job, $context, 'analysis_not_found');
        }
        if (!in_array($analysisStatus, ['validating', 'completed'], true)
            || ($analysisStatus === 'validating' && $reservationStatus !== 'reserved')
            || ($analysisStatus === 'completed' && $reservationStatus !== 'consumed')
            || !is_string($context['analysis']['validated_response_json'])
            || $context['analysis']['validated_response_json'] === ''
        ) {
            return $this->reconcileFailure($job, $context, 'analysis_not_found');
        }

        try {
            $validated = $this->validator->validate(
                $context['analysis']['validated_response_json'],
                $context['duration']
            );
        } catch (InvalidAnalysisResponse) {
            return $this->reconcileFailure($job, $context, 'analysis_not_found');
        } catch (Throwable) {
            return $this->reconcileFailure($job, $context, 'processing_persistence_failed');
        }

        try {
            $applied = $this->effects->apply($job, function () use ($job, $context, $validated): void {
                $consumed = $this->credits->consume((int) $context['generic_reservation']->id());
                if (!$consumed instanceof CreditReservation
                    || $consumed->status() !== 'consumed'
                    || $consumed->id() !== $context['generic_reservation']->id()
                    || $consumed->userId() !== $context['owner_id']
                    || $consumed->projectId() !== $job->projectId()
                    || $consumed->units() !== $context['units']
                ) {
                    throw new RuntimeException('AI reservation could not be consumed.');
                }

                $locked = $this->analyses->findLockedForProject(
                    (int) $context['analysis']['id'],
                    $job->projectId()
                );
                if (!is_array($locked)
                    || !is_string($locked['validated_response_json'])
                    || !is_int($locked['duration_seconds'])
                    || $locked['duration_seconds'] !== $context['duration']
                ) {
                    throw new RuntimeException('AI analysis changed before materialization.');
                }
                $binding = $this->reservations->findForAnalysis(
                    $consumed->id(),
                    $consumed->userId(),
                    $consumed->projectId(),
                    $consumed->units(),
                    (string) $locked['prompt_version']
                );
                if (!$binding instanceof CreditReservation || $binding->status() !== 'consumed') {
                    throw new RuntimeException('AI reservation binding changed before materialization.');
                }
                $revalidated = $this->validator->validate(
                    $locked['validated_response_json'],
                    $locked['duration_seconds']
                );
                if ($revalidated->videoSummary() !== $validated->videoSummary()) {
                    throw new RuntimeException('AI analysis changed before materialization.');
                }
                $this->clips->materialize((int) $locked['id'], $job->projectId(), $revalidated);
                $this->analyses->markCompleted((int) $locked['id']);
                $this->projects->advanceProcessingState($job->projectId(), 'suggestions_ready');
            });
        } catch (InvalidAnalysisResponse) {
            return $this->reconcileFailure($job, $context, 'analysis_not_found');
        } catch (PDOException) {
            if ($job->attempts() < $job->maxAttempts()) {
                return JobOutcome::retry(
                    'processing_persistence_failed',
                    ProcessingErrorCatalog::requireMessage('processing_persistence_failed')
                );
            }

            return $this->reconcileFailure($job, $context, 'processing_persistence_failed');
        } catch (Throwable) {
            return $this->reconcileFailure($job, $context, 'processing_persistence_failed');
        }

        if (!$applied) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }
        if ($this->automaticExports === null) {
            return JobOutcome::completed();
        }

        // Media inspection must run between effects, never while the effect guard holds locks.
        try {
            $prepared = $this->automaticExportPreflight === null ? null : ($this->automaticExportPreflight)(
                (int)$context['analysis']['id'],$job->projectId(),$context['owner_id']
            );
        } catch (Throwable) {
            // Materialization/consumption are durable and idempotent; retry only export admission.
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }
        try {
            $scheduled = $this->effects->apply($job, function () use ($job, $context, $prepared): void {
                ($this->automaticExports)(
                    (int) $context['analysis']['id'],
                    $job->projectId(),
                    $context['owner_id'],
                    $prepared
                );
                $this->projects->synchronizeRenderState($job->projectId());
            });
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->failedOutcome('processing_persistence_failed');
        }

        return $scheduled ? JobOutcome::completed() : JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
    }

    /** @param array<string, mixed> $context */
    private function reconcileFailure(ClaimedJob $job, array $context, string $code): JobOutcome
    {
        $message = ProcessingErrorCatalog::requireMessage($code);
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context, $code, $message): void {
                $reservation = $context['reservation'];
                if (!$reservation instanceof CreditReservation
                    || $reservation->projectId() !== $job->projectId()
                    || $reservation->userId() !== $context['owner_id']
                    || $reservation->units() !== $context['units']
                ) {
                    throw new RuntimeException('AI reservation binding is unavailable for reconciliation.');
                }
                $refunded = $this->credits->refund($reservation->id(), $code);
                if (!$refunded instanceof CreditReservation
                    || $refunded->id() !== $reservation->id()
                    || $refunded->userId() !== $context['owner_id']
                    || $refunded->projectId() !== $job->projectId()
                    || $refunded->units() !== $context['units']
                    || !in_array($refunded->status(), ['refunded', 'consumed'], true)
                ) {
                    throw new RuntimeException('AI reservation could not be reconciled.');
                }
                $analysis = $this->analyses->findLockedForProject(
                    (int) $context['analysis']['id'],
                    $job->projectId()
                );
                if (!is_array($analysis)) {
                    throw new RuntimeException('AI analysis changed during reconciliation.');
                }
                $this->analyses->markFailed((int) $analysis['id'], $code, $message);
                $this->projects->advanceProcessingState($job->projectId(), 'failed', $code, $message);
            });
        } catch (Throwable) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }

        return $applied ? JobOutcome::failed($code, $message) : JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
    }

    /** @param array<string, mixed> $context */
    private function failWithoutRefund(ClaimedJob $job, array $context, string $code): JobOutcome
    {
        $message = ProcessingErrorCatalog::requireMessage($code);
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context, $code, $message): void {
                $analysis = $this->analyses->findLockedForProject(
                    (int) $context['analysis']['id'],
                    $job->projectId()
                );
                if (!is_array($analysis)) {
                    throw new RuntimeException('AI analysis changed during terminal rejection.');
                }
                $this->analyses->markFailed((int) $analysis['id'], $code, $message);
                $this->projects->advanceProcessingState($job->projectId(), 'failed', $code, $message);
            });
        } catch (Throwable) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }

        return $applied ? JobOutcome::failed($code, $message) : JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
    }

    /** @param array{analysis_id: int, reservation_id: int} $payload
     *  @return array<string, mixed>|null
     */
    private function context(ClaimedJob $job, array $payload): ?array
    {
        $analysis = $this->analyses->findForProject($payload['analysis_id'], $job->projectId());
        $ownerId = $this->projects->ownerId($job->projectId());
        if (!is_array($analysis) || $ownerId === null
            || !is_int($analysis['duration_seconds']) || $analysis['duration_seconds'] < 1
        ) {
            return null;
        }
        $units = $this->credits->costForDuration($analysis['duration_seconds']);
        $generic = $this->reservations->findById($payload['reservation_id']);
        $reservation = $this->reservations->findForAnalysis(
            $payload['reservation_id'],
            $ownerId,
            $job->projectId(),
            $units,
            (string) $analysis['prompt_version']
        );

        return [
            'analysis' => $analysis,
            'duration' => $analysis['duration_seconds'],
            'owner_id' => $ownerId,
            'units' => $units,
            'generic_reservation' => $generic,
            'reservation' => $reservation,
        ];
    }

    /** @param array<string, mixed> $payload
     *  @return array{analysis_id: int, reservation_id: int}|null
     */
    private function exactPayload(array $payload): ?array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['analysis_id', 'reservation_id']) {
            return null;
        }
        if (!is_int($payload['analysis_id']) || $payload['analysis_id'] < 1
            || !is_int($payload['reservation_id']) || $payload['reservation_id'] < 1
        ) {
            return null;
        }

        return [
            'analysis_id' => $payload['analysis_id'],
            'reservation_id' => $payload['reservation_id'],
        ];
    }

    private function failedOutcome(string $code): JobOutcome
    {
        return JobOutcome::failed($code, ProcessingErrorCatalog::requireMessage($code));
    }
}
