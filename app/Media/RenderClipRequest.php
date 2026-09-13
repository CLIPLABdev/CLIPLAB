<?php

declare(strict_types=1);

namespace App\Media;

use App\Media\Reframe\ReframePlan;
use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\Transcript;
use InvalidArgumentException;

final class RenderClipRequest
{
    private ProjectSource $source;
    private float $startTime;
    private float $durationSeconds;
    private string $jobToken;
    private ReframePlan $reframePlan;
    private EditorOptions $editorOptions;
    private ?Transcript $transcript;

    public function __construct(
        ProjectSource $source,
        float $startTime,
        float $durationSeconds,
        string $jobToken,
        ?ReframePlan $reframePlan = null,
        ?EditorOptions $editorOptions = null,
        ?Transcript $transcript = null
    )
    {
        if (!is_finite($startTime) || $startTime < 0.0) {
            throw new InvalidArgumentException('Render start time must be finite and non-negative.');
        }
        if (!is_finite($durationSeconds) || $durationSeconds < 1.0 || $durationSeconds > 180.0) {
            throw new InvalidArgumentException('Render duration must be between 1 and 180 seconds.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $jobToken) !== 1) {
            throw new InvalidArgumentException('Render job token must be lowercase hexadecimal.');
        }

        $this->source = $source;
        $this->startTime = $startTime;
        $this->durationSeconds = $durationSeconds;
        $this->jobToken = $jobToken;
        $this->reframePlan = $reframePlan ?? ReframePlan::original();
        $this->editorOptions = $editorOptions ?? EditorOptions::fromArray([]);
        $this->transcript = $transcript;
    }

    public function source(): ProjectSource { return $this->source; }
    public function startTime(): float { return $this->startTime; }
    public function durationSeconds(): float { return $this->durationSeconds; }
    public function jobToken(): string { return $this->jobToken; }
    public function reframePlan(): ReframePlan { return $this->reframePlan; }
    public function editorOptions(): EditorOptions { return $this->editorOptions; }
    public function transcript(): ?Transcript { return $this->transcript; }
}
