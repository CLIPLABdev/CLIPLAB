<?php

declare(strict_types=1);

namespace App\Ai;

use InvalidArgumentException;

final class AiClipSuggestion
{
    private const MAX_DECIMAL_VALUE = 9999999.999;
    private const DECIMAL_EPSILON = 0.000000001;
    private const MILLISECONDS_PER_SECOND = 1000;
    private const DURATION_TOLERANCE_MILLISECONDS = 250;

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

    private int $index;
    private string $title;
    private float $startTime;
    private float $endTime;
    private float $duration;
    private int $score;
    private string $reason;
    private string $hook;
    private string $category;

    public function __construct(
        int $index,
        string $title,
        float $startTime,
        float $endTime,
        float $duration,
        int $score,
        string $reason,
        string $hook,
        string $category
    ) {
        if ($index < 0 || $index > 9) {
            throw new InvalidArgumentException('Clip suggestion index must be between zero and nine.');
        }

        $this->assertText($title, 180, 'title');
        $this->assertText($hook, 500, 'hook');
        $this->assertText($reason, 1000, 'reason');
        $this->assertText($category, 32, 'category');

        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw new InvalidArgumentException('Clip suggestion category is invalid.');
        }

        if (!is_finite($startTime) || !is_finite($endTime) || !is_finite($duration)) {
            throw new InvalidArgumentException('Clip suggestion timeline must contain finite numbers.');
        }

        $startTime = $this->canonicalDatabaseValue($startTime);
        $endTime = $this->canonicalDatabaseValue($endTime);
        $duration = $this->canonicalDatabaseValue($duration);

        if (abs($startTime) > self::MAX_DECIMAL_VALUE
            || abs($endTime) > self::MAX_DECIMAL_VALUE
            || abs($duration) > self::MAX_DECIMAL_VALUE
        ) {
            throw new InvalidArgumentException('Clip suggestion timeline is invalid.');
        }

        $startMilliseconds = $this->toDatabaseMilliseconds($startTime);
        $endMilliseconds = $this->toDatabaseMilliseconds($endTime);
        $durationMilliseconds = $this->toDatabaseMilliseconds($duration);

        if ($startMilliseconds < 0
            || $endMilliseconds <= $startMilliseconds
            || $durationMilliseconds <= 0
            || abs(($endMilliseconds - $startMilliseconds) - $durationMilliseconds)
                > self::DURATION_TOLERANCE_MILLISECONDS
        ) {
            throw new InvalidArgumentException('Clip suggestion timeline is invalid.');
        }

        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException('Clip suggestion score must be between zero and one hundred.');
        }

        $this->index = $index;
        $this->title = $title;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->duration = $duration;
        $this->score = $score;
        $this->reason = $reason;
        $this->hook = $hook;
        $this->category = $category;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function startTime(): float
    {
        return $this->startTime;
    }

    public function endTime(): float
    {
        return $this->endTime;
    }

    public function duration(): float
    {
        return $this->duration;
    }

    public function score(): int
    {
        return $this->score;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function hook(): string
    {
        return $this->hook;
    }

    public function category(): string
    {
        return $this->category;
    }

    private function assertText(string $value, int $maximumLength, string $field): void
    {
        if (trim($value) === ''
            || mb_strlen($value, 'UTF-8') > $maximumLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 0
        ) {
            throw new InvalidArgumentException("Clip suggestion {$field} is invalid.");
        }
    }

    private function canonicalDatabaseValue(float $value): float
    {
        $rounded = round($value, 3);
        if (abs($value - $rounded) > self::DECIMAL_EPSILON) {
            throw new InvalidArgumentException('Clip suggestion timeline exceeds database precision.');
        }

        return $rounded;
    }

    private function toDatabaseMilliseconds(float $value): int
    {
        return (int) round($value * self::MILLISECONDS_PER_SECOND);
    }
}
