<?php

declare(strict_types=1);

namespace App\Ai;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class AnalysisResponseValidator
{
    private const MAX_RESPONSE_BYTES = 1048576;
    private const MILLISECONDS_PER_SECOND = 1000;
    private const MINIMUM_REGULAR_CLIP_MILLISECONDS = 20000;
    private const MAXIMUM_CLIP_MILLISECONDS = 90000;
    private const DURATION_TOLERANCE_MILLISECONDS = 250;
    private const ROOT_FIELDS = ['video_summary', 'clips'];
    private const CLIP_FIELDS = [
        'title',
        'start_time',
        'end_time',
        'duration',
        'score',
        'reason',
        'hook',
        'category',
    ];
    private const ALLOWED_CATEGORIES = [
        'educational',
        'story',
        'emotional',
        'humorous',
        'controversial',
        'insight',
        'question',
        'other',
    ];

    public function validate(string $json, int $durationSeconds): AiAnalysisResult
    {
        $this->assertSourceDuration($durationSeconds);

        if (strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new InvalidAnalysisResponse('response_too_large');
        }

        try {
            $decoded = json_decode($json, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new InvalidAnalysisResponse('invalid_json');
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidAnalysisResponse('invalid_shape');
        }

        $this->assertExactObjectKeys($decoded, self::ROOT_FIELDS);
        $summary = $this->validatedText($decoded->video_summary, 2000);

        if (!is_array($decoded->clips)) {
            throw new InvalidAnalysisResponse('invalid_shape');
        }
        $this->assertSequentialList($decoded->clips);

        $clipCount = count($decoded->clips);
        if ($clipCount < 1 || $clipCount > 10) {
            throw new InvalidAnalysisResponse('invalid_clip_count');
        }

        $sourceMilliseconds = $durationSeconds * self::MILLISECONDS_PER_SECOND;
        $seenIntervals = [];
        $suggestions = [];

        foreach ($decoded->clips as $index => $clip) {
            if (!$clip instanceof stdClass) {
                throw new InvalidAnalysisResponse('invalid_shape');
            }

            $this->assertExactObjectKeys($clip, self::CLIP_FIELDS);

            $title = $this->validatedText($clip->title, 180);
            $reason = $this->validatedText($clip->reason, 1000);
            $hook = $this->validatedText($clip->hook, 500);
            $category = $this->validatedText($clip->category, 32);

            if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
                throw new InvalidAnalysisResponse('invalid_category');
            }

            $startMilliseconds = $this->exactMilliseconds($clip->start_time, 'invalid_timestamp');
            $endMilliseconds = $this->exactMilliseconds($clip->end_time, 'invalid_timestamp');
            $durationMilliseconds = $this->exactMilliseconds($clip->duration, 'invalid_duration');
            $score = $this->validatedScore($clip->score);

            if ($durationMilliseconds <= 0 || $durationMilliseconds > self::MAXIMUM_CLIP_MILLISECONDS) {
                throw new InvalidAnalysisResponse('invalid_duration');
            }

            if ($startMilliseconds < 0
                || $startMilliseconds >= $endMilliseconds
                || $endMilliseconds > $sourceMilliseconds
            ) {
                throw new InvalidAnalysisResponse('invalid_timeline');
            }

            $spanMilliseconds = $endMilliseconds - $startMilliseconds;

            if ($durationSeconds < 20
                && ($startMilliseconds !== 0 || $endMilliseconds !== $sourceMilliseconds)
            ) {
                throw new InvalidAnalysisResponse('invalid_timeline');
            }

            $minimumSpan = min(self::MINIMUM_REGULAR_CLIP_MILLISECONDS, $sourceMilliseconds);
            if ($spanMilliseconds < $minimumSpan || $spanMilliseconds > self::MAXIMUM_CLIP_MILLISECONDS) {
                throw new InvalidAnalysisResponse('invalid_duration');
            }

            if (abs($spanMilliseconds - $durationMilliseconds) > self::DURATION_TOLERANCE_MILLISECONDS) {
                throw new InvalidAnalysisResponse('invalid_timeline');
            }

            $intervalKey = $startMilliseconds . ':' . $endMilliseconds;
            if (isset($seenIntervals[$intervalKey])) {
                throw new InvalidAnalysisResponse('duplicate_clip');
            }
            $seenIntervals[$intervalKey] = true;

            try {
                $suggestions[] = new AiClipSuggestion(
                    $index,
                    $title,
                    $startMilliseconds / self::MILLISECONDS_PER_SECOND,
                    $endMilliseconds / self::MILLISECONDS_PER_SECOND,
                    $durationMilliseconds / self::MILLISECONDS_PER_SECOND,
                    $score,
                    $reason,
                    $hook,
                    $category
                );
            } catch (InvalidArgumentException $exception) {
                throw new InvalidAnalysisResponse('invalid_domain');
            }
        }

        try {
            return new AiAnalysisResult($summary, $suggestions);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidAnalysisResponse('invalid_domain');
        }
    }

    /** @param list<string> $expectedKeys */
    private function assertExactObjectKeys(stdClass $value, array $expectedKeys): void
    {
        $properties = get_object_vars($value);
        if (count($properties) !== count($expectedKeys)) {
            throw new InvalidAnalysisResponse('invalid_fields');
        }

        foreach ($expectedKeys as $key) {
            if (!array_key_exists($key, $properties)) {
                throw new InvalidAnalysisResponse('invalid_fields');
            }
        }
    }

    /** @param array<mixed> $values */
    private function assertSequentialList(array $values): void
    {
        $expectedKey = 0;
        foreach ($values as $key => $_value) {
            if ($key !== $expectedKey) {
                throw new InvalidAnalysisResponse('invalid_shape');
            }
            $expectedKey++;
        }
    }

    /** @param mixed $value */
    private function validatedText($value, int $maximumLength): string
    {
        if (!is_string($value)
            || mb_strlen($value, 'UTF-8') > $maximumLength
            || preg_match('/\A[\p{Z}\p{Cf}\s]*\z/u', $value) === 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 0
        ) {
            throw new InvalidAnalysisResponse('invalid_text');
        }

        return $value;
    }

    /** @param mixed $value */
    private function exactMilliseconds($value, string $reasonCode): int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            throw new InvalidAnalysisResponse($reasonCode);
        }

        $seconds = (float) $value;
        if ($seconds > PHP_INT_MAX / self::MILLISECONDS_PER_SECOND
            || $seconds < PHP_INT_MIN / self::MILLISECONDS_PER_SECOND
        ) {
            throw new InvalidAnalysisResponse($reasonCode);
        }

        $milliseconds = (int) round($seconds * self::MILLISECONDS_PER_SECOND);
        if ((float) $milliseconds / self::MILLISECONDS_PER_SECOND !== $seconds) {
            throw new InvalidAnalysisResponse($reasonCode);
        }

        return $milliseconds;
    }

    /** @param mixed $value */
    private function validatedScore($value): int
    {
        if ((!is_int($value) && !is_float($value))
            || !is_finite((float) $value)
            || (float) $value !== floor((float) $value)
            || $value < 0
            || $value > 100
        ) {
            throw new InvalidAnalysisResponse('invalid_score');
        }

        return (int) $value;
    }

    private function assertSourceDuration(int $durationSeconds): void
    {
        if ($durationSeconds < 1 || $durationSeconds > 86400) {
            throw new InvalidArgumentException('Video duration must be between one second and one day.');
        }
    }
}
