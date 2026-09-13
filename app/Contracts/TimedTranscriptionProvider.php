<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Media\Subtitles\Transcript;

interface TimedTranscriptionProvider
{
    public function transcribe(string $wavBytes, int $durationMs): Transcript;
}
