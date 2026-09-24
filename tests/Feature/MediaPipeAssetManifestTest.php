<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Media\MediaPipeAssetManifestVerifier;
use PHPUnit\Framework\TestCase;

final class MediaPipeAssetManifestTest extends TestCase
{
    private const ASSET_ROOT = __DIR__ . '/../../public/assets/vendor/mediapipe-tasks-vision-1.0.1';

    public function testVendoredManifestHasPinnedPackageAndModelAndVerifies(): void
    {
        $manifest = json_decode((string) file_get_contents(self::ASSET_ROOT . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('1.0.1', $manifest['package']['version']);
        self::assertSame('blaze_face_short_range/float16/1', $manifest['model']['revision']);
        self::assertTrue((new MediaPipeAssetManifestVerifier())->verify(self::ASSET_ROOT));
    }

    public function testVerifierRejectsMissingExtraAndModifiedFiles(): void
    {
        $root = $this->copyAssetRoot();
        try {
            self::assertTrue(unlink($root . '/wasm/vision_wasm_internal.wasm'));
            self::assertFalse((new MediaPipeAssetManifestVerifier())->verify($root));
        } finally { $this->removeDirectory($root); }

        $root = $this->copyAssetRoot();
        try {
            self::assertNotFalse(file_put_contents($root . '/unexpected.txt', 'not allowlisted'));
            self::assertFalse((new MediaPipeAssetManifestVerifier())->verify($root));
        } finally { $this->removeDirectory($root); }

        $root = $this->copyAssetRoot();
        try {
            self::assertNotFalse(file_put_contents($root . '/LICENSE', 'altered bytes', FILE_APPEND));
            self::assertFalse((new MediaPipeAssetManifestVerifier())->verify($root));
        } finally { $this->removeDirectory($root); }
    }

    public function testVerifierRejectsSymlinkOrWindowsJunction(): void
    {
        $root = $this->copyAssetRoot();
        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                self::assertTrue(rename($root . '/wasm', $root . '/wasm-target'));
                exec(sprintf('cmd /c mklink /J "%s" "%s"', $root . '\\wasm', $root . '\\wasm-target'), $output, $exit);
                self::assertSame(0, $exit, implode("\n", $output));
            } else {
                $link = $root . '/vision_bundle.mjs';
                $target = $root . '/linked-bundle.mjs';
                self::assertTrue(rename($link, $target));
                self::assertTrue(symlink($target, $link));
            }
            self::assertFalse((new MediaPipeAssetManifestVerifier())->verify($root));
        } finally { $this->removeDirectory($root); }
    }

    private function copyAssetRoot(): string
    {
        $root = sys_get_temp_dir() . '/cliplab-mediapipe-' . bin2hex(random_bytes(6));
        self::copyDirectory(self::ASSET_ROOT, $root);
        return $root;
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        self::assertTrue(is_dir($source), 'The vendored MediaPipe fixture must exist.');
        self::assertTrue(mkdir($destination, 0700, true));
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) { self::assertTrue(mkdir($target, 0700, true)); continue; }
            self::assertTrue(copy($item->getPathname(), $target));
        }
    }

    private function removeDirectory(string $root): void
    {
        if (!is_dir($root)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($root);
    }
}
