<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Gemini\GeminiFile;
use App\Media\ProjectSource;

interface VideoAnalysisProvider
{
    public function upload(ProjectSource $source): GeminiFile;

    public function getFile(string $resourceName): GeminiFile;

    /** @param array<string, mixed> $responseSchema */
    public function generate(GeminiFile $file, string $prompt, array $responseSchema): string;

    public function deleteFile(string $resourceName): void;
}
