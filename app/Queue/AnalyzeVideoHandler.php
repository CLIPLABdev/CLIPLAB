<?php

declare(strict_types=1);

namespace App\Queue;

use App\Ai\AnalysisResponseValidator;
use App\Ai\InvalidAnalysisResponse;
use App\Ai\ViralClipPrompt;
use App\Contracts\JobDispatcher;
use App\Contracts\VideoAnalysisProvider;
use App\Credits\CreditReservation;
use App\Gemini\GeminiException;
use App\Gemini\GeminiFile;
use App\Media\ProjectSource;
use PDOException;
use RuntimeException;
use Throwable;

final class AnalyzeVideoHandler implements JobHandler
{
    private const CHECKPOINT_DEFER_SECONDS = 15;

    /** @var \Closure(array{job_id:int,project_id:int,analysis_id:int,validation_attempt:int,reason_code:string}):void|null */
    private ?\Closure $validationObserver;
    private ?\Closure $providerFailureObserver;

    public function __construct(
        private object $analyses,
        private object $sources,
        private object $projects,
        private object $reservations,
        private object $credits,
        private JobDispatcher $jobs,
        private VideoAnalysisProvider $provider,
        private ViralClipPrompt $prompt,
        private AnalysisResponseValidator $validator,
        private ProcessingEffectGuard $effects,
        private int $pollSeconds = 15,
        private int $validationAttempts = 2,
        ?callable $validationObserver = null,
        ?callable $providerFailureObserver = null
    ) {
        if ($pollSeconds < 5 || $pollSeconds > 300 || $validationAttempts < 1 || $validationAttempts > 10) {
            throw new \InvalidArgumentException('AI handler retry limits are invalid.');
        }
        $this->validationObserver = $validationObserver === null ? null : \Closure::fromCallable($validationObserver);
        $this->providerFailureObserver = $providerFailureObserver === null ? null : \Closure::fromCallable($providerFailureObserver);
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

        $analysis = $context['analysis'];
        if (is_string($analysis['validated_response_json']) && $analysis['validated_response_json'] !== ''
            && is_string($analysis['gemini_file_name']) && $analysis['gemini_file_name'] !== ''
            && in_array($analysis['status'], ['validating', 'completed', 'failed'], true)
            && $context['source'] instanceof ProjectSource
        ) {
            return $this->cleanupRemoteFile($job, $context);
        }
        if ($analysis['status'] === 'completed') {
            return JobOutcome::completed();
        }
        if ($analysis['status'] === 'failed') {
            $code = is_string($analysis['error_code']) && ProcessingErrorCatalog::message($analysis['error_code']) !== null
                ? $analysis['error_code']
                : 'analysis_not_found';

            return $this->failedOutcome($code);
        }
        if (!$context['generic_reservation'] instanceof CreditReservation
            || $context['generic_reservation']->projectId() !== $job->projectId()
            || $context['generic_reservation']->userId() !== $context['owner_id']
        ) {
            return $this->failedOutcome('analysis_not_found');
        }
        if (!$context['reservation'] instanceof CreditReservation) {
            return $this->reconcileFailure($job, $context, 'analysis_not_found');
        }
        if ($context['reservation']->status() !== 'reserved') {
            return $this->failedOutcome('analysis_not_found');
        }
        if (!$context['source'] instanceof ProjectSource) {
            return $this->reconcileFailure($job, $context, 'analysis_not_found');
        }

        if ($analysis['gemini_file_name'] === null) {
            return $this->upload($job, $context);
        }

        try {
            $file = $this->fileFromAnalysis($analysis);
        } catch (Throwable) {
            return $this->reconcileFailure($job, $context, 'ai_provider_rejected');
        }
        if ($file->state() === 'FAILED') {
            return $this->reconcileFailure($job, $context, 'ai_file_failed');
        }
        if ($file->state() === 'PROCESSING') {
            return $this->poll($job, $context, $file);
        }
        if ((int) $analysis['validation_attempts'] >= $this->validationAttempts) {
            return $this->reconcileFailure($job, $context, 'ai_response_invalid');
        }

        return $this->generate($job, $context, $file);
    }

    /** @param array<string, mixed> $context */
    private function upload(ClaimedJob $job, array $context): JobOutcome
    {
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context): void {
                $this->assertReservedAndCurrent($job, $context, false);
                $this->analyses->markUploading((int) $context['analysis']['id']);
                $this->projects->advanceProcessingState($job->projectId(), 'uploading_ai');
            });
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->failedOutcome('analysis_not_found');
        }
        if (!$applied) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }

        try {
            $file = $this->provider->upload($context['source']);
        } catch (GeminiException $exception) {
            return $this->providerFailure($job, $context, $exception);
        } catch (Throwable) {
            return $this->providerFailure($job, $context, GeminiException::withCode('ai_unavailable'));
        }

        return $this->checkpointFile($job, $context, $file);
    }

    /** @param array<string, mixed> $context */
    private function poll(ClaimedJob $job, array $context, GeminiFile $current): JobOutcome
    {
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context): void {
                $this->assertReservedAndCurrent($job, $context, true);
                $this->projects->advanceProcessingState($job->projectId(), 'waiting_ai_file');
            });
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->failedOutcome('analysis_not_found');
        }
        if (!$applied) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }

        try {
            $file = $this->provider->getFile($current->name());
        } catch (GeminiException $exception) {
            return $this->providerFailure($job, $context, $exception);
        } catch (Throwable) {
            return $this->providerFailure($job, $context, GeminiException::withCode('ai_unavailable'));
        }

        return $this->checkpointFile($job, $context, $file);
    }

    /** @param array<string, mixed> $context */
    private function checkpointFile(ClaimedJob $job, array $context, GeminiFile $file): JobOutcome
    {
        if ($file->state() === 'FAILED') {
            return $this->reconcileFailure($job, $context, 'ai_file_failed');
        }
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context, $file): void {
                $this->assertReservedAndCurrent($job, $context, false);
                $this->analyses->checkpointFile((int) $context['analysis']['id'], $file);
                $this->projects->advanceProcessingState(
                    $job->projectId(),
                    $file->state() === 'ACTIVE' ? 'analyzing' : 'waiting_ai_file'
                );
            });
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->reconcileFailure($job, $context, 'processing_persistence_failed');
        }

        return $applied ? JobOutcome::deferred($this->pollSeconds) : JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
    }

    /** @param array<string, mixed> $context */
    private function generate(ClaimedJob $job, array $context, GeminiFile $file): JobOutcome
    {
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context): void {
                $this->assertReservedAndCurrent($job, $context, true);
                $this->projects->advanceProcessingState($job->projectId(), 'analyzing');
            });
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->failedOutcome('analysis_not_found');
        }
        if (!$applied) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }

        try {
            $raw = $this->provider->generate(
                $file,
                $this->prompt->text($context['duration'], (int) $context['analysis']['validation_attempts'] > 0),
                $this->prompt->responseSchema($context['duration'])
            );
        } catch (GeminiException $exception) {
            return $this->providerFailure($job, $context, $exception);
        } catch (Throwable) {
            return $this->providerFailure($job, $context, GeminiException::withCode('ai_unavailable'));
        }

        try {
            $validated = $this->validator->validate($raw, $context['duration']);
            $invalid = false;
        } catch (InvalidAnalysisResponse $exception) {
            $validated = null;
            $invalid = true;
            // Observation only: the guarded durable checkpoint below may still lose its lease.
            try {
                if ($this->validationObserver !== null) {
                    ($this->validationObserver)([
                        'job_id' => $job->id(),
                        'project_id' => $job->projectId(),
                        'analysis_id' => (int) $context['analysis']['id'],
                        'validation_attempt' => (int) $context['analysis']['validation_attempts'] + 1,
                        'reason_code' => $exception->reasonCode(),
                    ]);
                }
            } catch (Throwable) {
                // Best-effort diagnostics must not change validation, retries or credit reconciliation.
            }
        }
        $nextAttempt = (int) $context['analysis']['validation_attempts'] + 1;
        if ($invalid && $nextAttempt >= $this->validationAttempts) {
            return $this->terminalInvalidResponse($job, $context, $raw);
        }

        try {
            $applied = $this->effects->apply($job, function () use ($job, $context, $raw, $invalid, $validated): void {
                $locked = $this->assertReservedAndCurrent($job, $context, true);
                if ((int) $locked['validation_attempts'] >= $this->validationAttempts) {
                    throw new RuntimeException('AI validation attempt limit was reached.');
                }
                $this->analyses->incrementValidationAttempts((int) $locked['id']);
                if ($invalid) {
                    try {
                        $this->validator->validate($raw, $context['duration']);
                        throw new RuntimeException('AI validation result changed unexpectedly.');
                    } catch (InvalidAnalysisResponse) {
                        return;
                    }
                }
                $revalidated = $this->validator->validate($raw, $context['duration']);
                if ($validated === null || $revalidated->videoSummary() !== $validated->videoSummary()) {
                    throw new RuntimeException('AI validation result changed unexpectedly.');
                }
                $this->analyses->storeValidatedResult((int) $locked['id'], $raw, $revalidated);
                $this->jobs->dispatch('generate_clips', $job->projectId(), [
                    'analysis_id' => (int) $locked['id'],
                    'reservation_id' => (int) $context['reservation']->id(),
                ], 'ai:clips:' . (int) $locked['id'] . ':' . (string) $locked['prompt_version']);
                $this->projects->advanceProcessingState($job->projectId(), 'identifying_clips');
            });
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return $this->reconcileFailure($job, $context, 'processing_persistence_failed');
        }

        return $applied ? JobOutcome::deferred($this->pollSeconds) : JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
    }

    /** @param array<string, mixed> $context */
    private function terminalInvalidResponse(ClaimedJob $job, array $context, string $raw): JobOutcome
    {
        $code = 'ai_response_invalid';
        $message = ProcessingErrorCatalog::requireMessage($code);
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context, $raw, $code, $message): void {
                $refunded = $this->credits->refund((int) $context['reservation']->id(), $code);
                if (!$refunded instanceof CreditReservation
                    || $refunded->id() !== $context['reservation']->id()
                    || $refunded->userId() !== $context['owner_id']
                    || $refunded->projectId() !== $job->projectId()
                    || $refunded->units() !== $context['units']
                    || !in_array($refunded->status(), ['refunded', 'consumed'], true)
                ) {
                    throw new RuntimeException('AI reservation could not be reconciled.');
                }
                $locked = $this->analyses->findLockedForProject((int) $context['analysis']['id'], $job->projectId());
                if (!is_array($locked)) {
                    throw new RuntimeException('AI analysis changed during validation.');
                }
                $this->analyses->incrementValidationAttempts((int) $locked['id']);
                try {
                    $this->validator->validate($raw, $context['duration']);
                    throw new RuntimeException('AI validation result changed unexpectedly.');
                } catch (InvalidAnalysisResponse) {
                    // The durable second invalid response is terminal.
                }
                $this->analyses->markFailed((int) $locked['id'], $code, $message);
                $this->projects->advanceProcessingState($job->projectId(), 'failed', $code, $message);
            });
        } catch (PDOException) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        } catch (Throwable) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }

        return $applied ? JobOutcome::failed($code, $message) : JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
    }

    /** @param array<string, mixed> $context */
    private function providerFailure(ClaimedJob $job, array $context, GeminiException $exception): JobOutcome
    {
        try {
            if ($this->providerFailureObserver !== null) {
                $analysis = $context['analysis'];
                $phase = $analysis['gemini_file_name'] === null ? 'upload'
                    : ($analysis['gemini_file_state'] === 'PROCESSING' ? 'poll' : 'generate');
                ($this->providerFailureObserver)([
                    'job_id' => $job->id(),
                    'project_id' => $job->projectId(),
                    'analysis_id' => (int) $analysis['id'],
                    'attempt' => $job->attempts(),
                    'max_attempts' => $job->maxAttempts(),
                    'phase' => $phase,
                    'result_code' => $exception->publicCode(),
                ] + $exception->safeDiagnostic());
            }
        } catch (Throwable) {
            // Diagnostics never interrupt retries or the terminal credit refund.
        }
        if ($exception->isTransient() && $job->attempts() < $job->maxAttempts()) {
            return JobOutcome::retry($exception->publicCode(), $exception->getMessage(), $exception->retryAfterSeconds());
        }

        return $this->reconcileFailure($job, $context, $exception->publicCode());
    }

    /** @param array<string, mixed> $context */
    private function reconcileFailure(ClaimedJob $job, array $context, string $code): JobOutcome
    {
        $message = ProcessingErrorCatalog::requireMessage($code);
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context, $code, $message): void {
                $target = $context['reservation'] instanceof CreditReservation
                    ? $context['reservation']
                    : $context['generic_reservation'];
                if (!$target instanceof CreditReservation
                    || $target->projectId() !== $job->projectId()
                    || $target->userId() !== $context['owner_id']
                ) {
                    throw new RuntimeException('AI reservation could not be reconciled.');
                }
                $reservation = $this->credits->refund($target->id(), $code);
                if (!$reservation instanceof CreditReservation
                    || $reservation->id() !== $target->id()
                    || $reservation->userId() !== $context['owner_id']
                    || $reservation->projectId() !== $job->projectId()
                    || $reservation->units() !== $context['units']
                    || !in_array($reservation->status(), ['refunded', 'consumed'], true)
                ) {
                    throw new RuntimeException('AI reservation could not be reconciled.');
                }
                $analysis = $this->analyses->findLockedForProject((int) $context['analysis']['id'], $job->projectId());
                if (!is_array($analysis)) {
                    throw new RuntimeException('AI analysis changed during failure reconciliation.');
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
    private function cleanupRemoteFile(ClaimedJob $job, array $context): JobOutcome
    {
        try {
            $applied = $this->effects->apply($job, function () use ($job, $context): void {
                $reservation = $this->reservations->findForAnalysis(
                    (int) $context['generic_reservation']->id(),
                    (int) $context['owner_id'],
                    $job->projectId(),
                    (int) $context['units'],
                    (string) $context['analysis']['prompt_version']
                );
                if (!$reservation instanceof CreditReservation
                    || !in_array($reservation->status(), ['reserved', 'consumed', 'refunded'], true)
                ) {
                    throw new RuntimeException('AI reservation changed before cleanup.');
                }
                $analysis = $this->analyses->findLockedForProject(
                    (int) $context['analysis']['id'],
                    $job->projectId()
                );
                if (!is_array($analysis)
                    || !in_array($analysis['status'], ['validating', 'completed', 'failed'], true)
                    || !is_string($analysis['validated_response_json'])
                ) {
                    throw new RuntimeException('AI analysis changed before cleanup.');
                }
            });
        } catch (Throwable) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }
        if (!$applied) {
            return JobOutcome::deferred(self::CHECKPOINT_DEFER_SECONDS);
        }
        $name = $context['analysis']['gemini_file_name'];
        if (is_string($name) && $name !== '') {
            try {
                $this->provider->deleteFile($name);
            } catch (Throwable) {
                // Cleanup is best effort; no provider detail is persisted or exposed.
            }
        }

        if ($context['analysis']['status'] === 'failed') {
            $code = is_string($context['analysis']['error_code'])
                && ProcessingErrorCatalog::message($context['analysis']['error_code']) !== null
                ? $context['analysis']['error_code']
                : 'analysis_not_found';

            return $this->failedOutcome($code);
        }

        return JobOutcome::completed();
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function assertReservedAndCurrent(ClaimedJob $job, array $context, bool $requiresFile): array
    {
        $reservation = $this->reservations->findForAnalysis(
            (int) $context['reservation']->id(),
            (int) $context['owner_id'],
            $job->projectId(),
            (int) $context['units'],
            (string) $context['analysis']['prompt_version']
        );
        if (!$reservation instanceof CreditReservation || $reservation->status() !== 'reserved') {
            throw new RuntimeException('AI reservation changed during processing.');
        }
        $analysis = $this->analyses->findLockedForProject((int) $context['analysis']['id'], $job->projectId());
        if (!is_array($analysis) || in_array($analysis['status'], ['completed', 'failed'], true)
            || ($requiresFile && !is_string($analysis['gemini_file_name']))
        ) {
            throw new RuntimeException('AI analysis changed during processing.');
        }

        return $analysis;
    }

    /** @param array{analysis_id: int, source_id: int, reservation_id: int} $payload
     *  @return array<string, mixed>|null
     */
    private function context(ClaimedJob $job, array $payload): ?array
    {
        $analysis = $this->analyses->findForProject($payload['analysis_id'], $job->projectId());
        $ready = $this->sources->findReadyForAnalysis($payload['source_id'], $job->projectId());
        $ownerId = $this->projects->ownerId($job->projectId());
        if (!is_array($analysis) || $ownerId === null
        ) {
            return null;
        }
        $duration = is_int($analysis['duration_seconds'] ?? null)
            ? $analysis['duration_seconds']
            : (is_array($ready) && is_int($ready['duration_seconds'] ?? null) ? $ready['duration_seconds'] : null);
        if (!is_int($duration) || $duration < 1) {
            return null;
        }
        $units = $this->credits->costForDuration($duration);
        $generic = $this->reservations->findById($payload['reservation_id']);
        $reservation = $this->reservations->findForAnalysis(
            $payload['reservation_id'],
            $ownerId,
            $job->projectId(),
            $units,
            (string) $analysis['prompt_version']
        );
        if (!$reservation instanceof CreditReservation) {
            return null;
        }

        return [
            'analysis' => $analysis,
            'source' => is_array($ready)
                && ($ready['source'] ?? null) instanceof ProjectSource
                && ($ready['duration_seconds'] ?? null) === $duration
                ? $ready['source']
                : null,
            'duration' => $duration,
            'owner_id' => $ownerId,
            'units' => $units,
            'generic_reservation' => $generic,
            'reservation' => $reservation,
        ];
    }

    /** @param array<string, mixed> $payload
     *  @return array{analysis_id: int, source_id: int, reservation_id: int}|null
     */
    private function exactPayload(array $payload): ?array
    {
        $expected = ['analysis_id', 'reservation_id', 'source_id'];
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== $expected) {
            return null;
        }
        foreach ($expected as $key) {
            if (!is_int($payload[$key]) || $payload[$key] < 1) {
                return null;
            }
        }

        return [
            'analysis_id' => $payload['analysis_id'],
            'source_id' => $payload['source_id'],
            'reservation_id' => $payload['reservation_id'],
        ];
    }

    /** @param array<string, mixed> $analysis */
    private function fileFromAnalysis(array $analysis): GeminiFile
    {
        return new GeminiFile(
            (string) $analysis['gemini_file_name'],
            (string) $analysis['gemini_file_uri'],
            (string) $analysis['gemini_file_mime'],
            (string) $analysis['gemini_file_state']
        );
    }

    private function failedOutcome(string $code): JobOutcome
    {
        return JobOutcome::failed($code, ProcessingErrorCatalog::requireMessage($code));
    }
}
