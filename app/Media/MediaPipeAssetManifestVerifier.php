<?php

declare(strict_types=1);

namespace App\Media;

final class MediaPipeAssetManifestVerifier
{
    public const VERSION = '1.0.1';

    private const ALLOWED = [
        'LICENSE', 'manifest.json', 'vision_bundle.mjs',
        'models/blaze_face_short_range_float16.tflite',
        'wasm/vision_wasm_internal.js', 'wasm/vision_wasm_internal.wasm',
        'wasm/vision_wasm_nosimd_internal.js', 'wasm/vision_wasm_nosimd_internal.wasm',
    ];

    private const HASHED = [
        'LICENSE', 'vision_bundle.mjs', 'models/blaze_face_short_range_float16.tflite',
        'wasm/vision_wasm_internal.js', 'wasm/vision_wasm_internal.wasm',
        'wasm/vision_wasm_nosimd_internal.js', 'wasm/vision_wasm_nosimd_internal.wasm',
    ];

    public function verify(string $versionedAssetRoot): bool
    {
        try {
            if ($versionedAssetRoot === '' || $this->isReparse($versionedAssetRoot) || !is_dir($versionedAssetRoot)) return false;
            $actual = $this->files($versionedAssetRoot);
            $allowed = self::ALLOWED;
            sort($allowed);
            if ($actual !== $allowed) return false;
            $manifestPath = $versionedAssetRoot . DIRECTORY_SEPARATOR . 'manifest.json';
            if ($this->isReparse($manifestPath)) return false;
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['package']['name'] ?? null) !== '@mediapipe/tasks-vision' || ($manifest['package']['version'] ?? null) !== self::VERSION || ($manifest['package']['source'] ?? null) !== 'https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-1.0.1.tgz' || ($manifest['package']['integrity'] ?? null) !== 'sha512-rvRE2FmAZ6ZxKSw7wq+e+jQDpN3t1B/tD2mJz9SmAzb1msoDkd4dMoE4wAh8Z30Um0PQwLiHr9QtomhmXk3aUQ==' || ($manifest['package']['license'] ?? null) !== 'Apache-2.0') return false;
            if (($manifest['model']['source'] ?? null) !== 'https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite' || ($manifest['model']['revision'] ?? null) !== 'blaze_face_short_range/float16/1' || !isset($manifest['files']) || !is_array($manifest['files']) || count($manifest['files']) !== count(self::HASHED)) return false;
            $records = [];
            foreach ($manifest['files'] as $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null) || !is_int($file['size_bytes'] ?? null) || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $file['sha256'])) return false;
                $records[$file['path']] = $file;
            }
            if (count($records) !== count(self::HASHED)) return false;
            foreach (self::HASHED as $path) {
                if (!isset($records[$path]) || str_contains($path, '..') || str_contains($path, '\\')) return false;
                $absolute = $versionedAssetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                if ($this->isReparse($absolute) || !is_file($absolute) || filesize($absolute) !== $records[$path]['size_bytes']) return false;
                $actualHash = hash_file('sha256', $absolute);
                if (!is_string($actualHash) || !hash_equals($records[$path]['sha256'], $actualHash)) return false;
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $files = [];
        $visit = function (string $directory) use (&$visit, &$files, $root): void {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if ($this->isReparse($path)) throw new \RuntimeException();
                if (is_dir($path)) { $visit($path); continue; }
                if (!is_file($path)) throw new \RuntimeException();
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            }
        };
        $visit($root);
        sort($files);
        return $files;
    }
    private function isReparse(string $path): bool
    {
        $stat = lstat($path);
        return $stat === false || is_link($path) || (DIRECTORY_SEPARATOR === '\\' && ($stat['mode'] ?? 0) === 0);
    }
}
