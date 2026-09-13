<?php

declare(strict_types=1);

namespace App\Services;

use App\Security\SecretCipher;
use PDO;
use RuntimeException;
use Throwable;

final class ApplicationEncryptionKeyProvisioner
{
    private string $projectRoot;

    public function __construct(private PDO $pdo, string $projectRoot)
    {
        $resolved = realpath($projectRoot);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('The project root is invalid.');
        }
        $this->projectRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /** @return 'created'|'already_configured' */
    public function provision(string $environmentFile): string
    {
        $this->assertPrivateRootEnvironmentFile($environmentFile);
        $lock = $this->openLock();
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('The encryption-key provisioning lock could not be acquired.');
            }
            $this->assertPrivateRootEnvironmentFile($environmentFile);
            $this->cleanupStaleTemporaryFiles();

            return $this->provisionWhileLocked($environmentFile);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return 'created'|'already_configured' */
    private function provisionWhileLocked(string $environmentFile): string
    {
        $contents = $this->readEnvironmentFile($environmentFile);
        $matches = [];
        preg_match_all('/^[\t ]*APP_ENCRYPTION_KEY[\t ]*=([^\r\n]*)\r?$/m', $contents, $matches);
        $values = $matches[1] ?? [];
        if (count($values) > 1) {
            throw new RuntimeException('The environment file contains duplicate APP_ENCRYPTION_KEY entries.');
        }

        $current = isset($values[0]) && is_string($values[0]) ? trim(trim($values[0]), "\"'") : '';
        $encryptedOverride = $this->encryptedOverride();
        if ($this->isValidKey($current)) {
            if ($encryptedOverride !== null) {
                try {
                    (new SecretCipher($current))->decrypt($encryptedOverride);
                } catch (Throwable) {
                    throw new RuntimeException('The configured master key cannot decrypt the existing Gemini override.');
                }
            }
            return 'already_configured';
        }
        if ($encryptedOverride !== null) {
            throw new RuntimeException('An encrypted Gemini override already exists; the master key cannot be generated or replaced.');
        }

        $encoded = base64_encode(random_bytes(32));
        $line = 'APP_ENCRYPTION_KEY=' . $encoded;
        if ($values !== []) {
            $updated = preg_replace_callback(
                '/^[\t ]*APP_ENCRYPTION_KEY[\t ]*=[^\r\n]*(\r?)$/m',
                static fn (array $match): string => $line . ($match[1] ?? ''),
                $contents,
                1
            );
            if (!is_string($updated)) {
                throw new RuntimeException('The environment file could not be updated.');
            }
        } else {
            $separator = str_contains($contents, "\r\n") ? "\r\n" : "\n";
            $updated = $contents;
            if ($updated !== '' && !str_ends_with($updated, "\n") && !str_ends_with($updated, "\r")) {
                $updated .= $separator;
            }
            $updated .= $line . $separator;
        }

        $this->replaceAtomically($environmentFile, $updated);

        return 'created';
    }

    /** @return resource */
    private function openLock()
    {
        $cache = realpath($this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
        $expected = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_string($cache) || !$this->pathsMatch(rtrim($cache, DIRECTORY_SEPARATOR), $expected)) {
            throw new RuntimeException('The private cache directory is unavailable.');
        }
        $path = $cache . DIRECTORY_SEPARATOR . 'application-encryption-key.lock';
        if (is_link($path)) {
            throw new RuntimeException('The encryption-key provisioning lock must not be a symbolic link.');
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || is_link($path)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('The encryption-key provisioning lock is unavailable.');
        }

        return $handle;
    }

    private function replaceAtomically(string $environmentFile, string $contents): void
    {
        $temporary = null;
        $handle = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $this->projectRoot . DIRECTORY_SEPARATOR . '.env.tmp.' . bin2hex(random_bytes(12));
            $candidateHandle = @fopen($candidate, 'x+b');
            if (is_resource($candidateHandle)) {
                $temporary = $candidate;
                $handle = $candidateHandle;
                break;
            }
        }
        if (!is_string($temporary) || !is_resource($handle) || !@chmod($temporary, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_string($temporary) && (is_file($temporary) || is_link($temporary))) {
                @unlink($temporary);
            }
            throw new RuntimeException('A private temporary environment file could not be created.');
        }
        try {
            $remaining = $contents;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('The environment file could not be updated.');
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('The environment file could not be updated.');
            }
            fclose($handle);
            $handle = null;
            $this->assertPrivateRootEnvironmentFile($environmentFile);
            if (!@rename($temporary, $environmentFile)) {
                throw new RuntimeException('The environment file could not be atomically replaced.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function cleanupStaleTemporaryFiles(): void
    {
        $pattern = $this->projectRoot . DIRECTORY_SEPARATOR . '.env.tmp.*';
        foreach (glob($pattern) ?: [] as $path) {
            if (preg_match('/\A\.env\.tmp\.[a-f0-9]{16,64}\z/D', basename($path)) !== 1) {
                continue;
            }
            if ((is_file($path) || is_link($path)) && !@unlink($path)) {
                throw new RuntimeException('A stale private environment file could not be removed.');
            }
        }
    }

    private function assertPrivateRootEnvironmentFile(string $environmentFile): void
    {
        if (is_link($environmentFile)) {
            throw new RuntimeException('The environment file must not be a symbolic link.');
        }
        $parent = realpath(dirname($environmentFile));
        $candidate = is_string($parent) ? rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($environmentFile) : '';
        $expected = $this->projectRoot . DIRECTORY_SEPARATOR . '.env';
        if (!$this->pathsMatch($candidate, $expected)) {
            throw new RuntimeException('The environment file must be the private project-root .env file.');
        }
    }

    private function pathsMatch(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : hash_equals($right, $left);
    }

    private function readEnvironmentFile(string $environmentFile): string
    {
        if (!file_exists($environmentFile)) {
            return '';
        }
        if (!is_file($environmentFile) || !is_readable($environmentFile)) {
            throw new RuntimeException('The environment file is not readable.');
        }
        $size = filesize($environmentFile);
        if (!is_int($size) || $size > 1048576) {
            throw new RuntimeException('The environment file is too large.');
        }
        $contents = file_get_contents($environmentFile);
        if (!is_string($contents)) {
            throw new RuntimeException('The environment file is not readable.');
        }

        return $contents;
    }

    private function isValidKey(string $encoded): bool
    {
        if ($encoded === '') {
            return false;
        }
        try {
            new SecretCipher($encoded);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function encryptedOverride(): ?string
    {
        $value = $this->pdo->query('SELECT api_key_ciphertext FROM gemini_settings WHERE id = 1 LIMIT 1')->fetchColumn();

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
