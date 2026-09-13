<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PrivateStorage;
use App\Contracts\RenderArtifactCleanupStore;
use App\Queue\WorkerMaintenance;
use InvalidArgumentException;
use Throwable;

final class RenderMaintenance implements WorkerMaintenance
{
    private string $temporaryDirectory;

    public function __construct(
        private RenderArtifactCleanupStore $cleanups,
        private PrivateStorage $storage,
        string $temporaryDirectory,
        private int $staleAfterSeconds
    ) {
        $resolved = realpath($temporaryDirectory);
        if ($resolved === false
            || !is_dir($resolved)
            || !is_writable($resolved)
            || $staleAfterSeconds < 1
            || $this->isInsideProjectPublicRoot($resolved)
        ) {
            throw new InvalidArgumentException('Render maintenance configuration is invalid.');
        }
        $this->temporaryDirectory = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function run(): void
    {
        try {
            $this->recoverPublishedObjects();
        } finally {
            $this->recoverStaleTemporaryFiles();
        }
    }

    private function recoverPublishedObjects(): void
    {
        foreach ($this->cleanups->pending() as $key) {
            try {
                $this->storage->delete($key);
                $this->cleanups->forget($key);
            } catch (Throwable) {
                // The durable row remains eligible for the next finite worker run.
            }
        }
    }

    private function recoverStaleTemporaryFiles(): void
    {
        $threshold = time() - $this->staleAfterSeconds;
        foreach (scandir($this->temporaryDirectory) ?: [] as $entry) {
            if (preg_match(
                '/\Aclipforge-(?:video-[a-f0-9]{32}\.mp4|thumbnail-[a-f0-9]{32}\.jpg|audio-[a-f0-9]{32}\.wav|subtitles-[a-f0-9]{32}\.ass)\z/D',
                $entry
            ) !== 1) {
                continue;
            }
            $path = $this->temporaryDirectory . DIRECTORY_SEPARATOR . $entry;
            $modifiedAt = is_file($path) && !is_link($path) ? filemtime($path) : false;
            if (is_int($modifiedAt) && $modifiedAt <= $threshold) {
                @unlink($path);
            }
        }
    }

    private function isInsideProjectPublicRoot(string $directory): bool
    {
        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot === false) {
            return false;
        }
        foreach (['public', 'public_html'] as $name) {
            $publicRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . $name);
            if ($publicRoot === false) {
                continue;
            }
            $candidate = DIRECTORY_SEPARATOR === '\\' ? strtolower($directory) : $directory;
            $expected = DIRECTORY_SEPARATOR === '\\' ? strtolower($publicRoot) : $publicRoot;
            if ($candidate === $expected
                || str_starts_with($candidate, rtrim($expected, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ) {
                return true;
            }
        }

        return false;
    }
}
