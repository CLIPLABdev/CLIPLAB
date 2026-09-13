<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ApplicationEncryptionKeyProvisioner;
use App\Security\SecretCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AdminTestDatabase;

final class ApplicationEncryptionKeyProvisionerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-key-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'public', 0700, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . DIRECTORY_SEPARATOR . '.env.tmp.*') ?: [] as $temporary) {
            @unlink($temporary);
        }
        foreach ([$this->root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.env', $this->root . DIRECTORY_SEPARATOR . '.env'] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @unlink($this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'application-encryption-key.lock');
        @rmdir($this->root . DIRECTORY_SEPARATOR . 'public');
        @rmdir($this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
        @rmdir($this->root . DIRECTORY_SEPARATOR . 'storage');
        @rmdir($this->root);
    }

    public function testCreatesAValidKeyWithoutReturningOrDisturbingOtherEnvironmentValues(): void
    {
        $pdo = AdminTestDatabase::create();
        $env = $this->root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($env, "APP_NAME=ClipForge\nGEMINI_MODEL=gemini-test\n");
        clearstatcache(true, $env);
        $originalFileId = fileinode($env);

        $result = $this->provisioner($pdo)->provision($env);
        clearstatcache(true, $env);
        $contents = (string) file_get_contents($env);
        preg_match('/^APP_ENCRYPTION_KEY=([^\r\n]+)$/m', $contents, $match);
        $decoded = isset($match[1]) ? base64_decode($match[1], true) : false;

        self::assertSame('created', $result);
        self::assertStringContainsString("APP_NAME=ClipForge\n", $contents);
        self::assertStringContainsString("GEMINI_MODEL=gemini-test\n", $contents);
        self::assertIsString($decoded);
        self::assertSame(32, strlen($decoded));
        self::assertStringNotContainsString((string) ($match[1] ?? ''), $result);
        self::assertNotSame($originalFileId, fileinode($env), 'The .env file was overwritten in place instead of atomically replaced.');
    }

    public function testExistingValidKeyIsAnIdempotentNoOp(): void
    {
        $pdo = AdminTestDatabase::create();
        $env = $this->root . DIRECTORY_SEPARATOR . '.env';
        $contents = 'APP_ENCRYPTION_KEY=' . base64_encode(str_repeat("\x61", 32)) . "\nAPP_NAME=ClipForge\n";
        file_put_contents($env, $contents);

        self::assertSame('already_configured', $this->provisioner($pdo)->provision($env));
        self::assertSame($contents, file_get_contents($env));
    }

    public function testReplacingAnInvalidEntryPreservesCrLfLineEndings(): void
    {
        $pdo = AdminTestDatabase::create();
        $env = $this->root . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($env, "APP_NAME=ClipForge\r\nAPP_ENCRYPTION_KEY=invalid\r\nGEMINI_MODEL=gemini-test\r\n");

        self::assertSame('created', $this->provisioner($pdo)->provision($env));
        $contents = (string) file_get_contents($env);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $contents));
        self::assertSame(3, substr_count($contents, "\r\n"));
    }

    public function testRemovesAStalePrivateTemporaryFileWhileHoldingTheProvisioningLock(): void
    {
        $pdo = AdminTestDatabase::create();
        $env = $this->root . DIRECTORY_SEPARATOR . '.env';
        $stale = $this->root . DIRECTORY_SEPARATOR . '.env.tmp.0123456789abcdef';
        file_put_contents($env, "APP_NAME=ClipForge\n");
        file_put_contents($stale, "DATABASE_PASSWORD=stale-secret\n");

        self::assertSame('created', $this->provisioner($pdo)->provision($env));
        self::assertFileDoesNotExist($stale);
    }

    public function testNeverGeneratesAReplacementWhileCiphertextExists(): void
    {
        $pdo = AdminTestDatabase::create();
        $pdo->exec("INSERT INTO gemini_settings (id, api_key_ciphertext, model) VALUES (1, 'v1.existing-ciphertext', 'gemini-test')");
        $env = $this->root . DIRECTORY_SEPARATOR . '.env';
        $contents = "APP_NAME=ClipForge\n";
        file_put_contents($env, $contents);

        try {
            $this->provisioner($pdo)->provision($env);
            self::fail('Provisioning replaced a missing master key while ciphertext existed.');
        } catch (RuntimeException $exception) {
            self::assertSame('An encrypted Gemini override already exists; the master key cannot be generated or replaced.', $exception->getMessage());
        }
        self::assertSame($contents, file_get_contents($env));
    }

    public function testExistingValidButWrongKeyDoesNotMaskAnUndecryptableOverride(): void
    {
        $pdo = AdminTestDatabase::create();
        $originalKey = base64_encode(str_repeat("\x31", 32));
        $differentKey = base64_encode(str_repeat("\x32", 32));
        $ciphertext = (new SecretCipher($originalKey))->encrypt('provider-secret');
        $statement = $pdo->prepare('INSERT INTO gemini_settings (id, api_key_ciphertext, model) VALUES (1, :ciphertext, :model)');
        $statement->execute(['ciphertext' => $ciphertext, 'model' => 'gemini-test']);
        $env = $this->root . DIRECTORY_SEPARATOR . '.env';
        $contents = 'APP_ENCRYPTION_KEY=' . $differentKey . "\n";
        file_put_contents($env, $contents);

        try {
            $this->provisioner($pdo)->provision($env);
            self::fail('A wrong master key was reported as configured for an existing ciphertext.');
        } catch (RuntimeException $exception) {
            self::assertSame('The configured master key cannot decrypt the existing Gemini override.', $exception->getMessage());
        }
        self::assertSame($contents, file_get_contents($env));
    }

    public function testRejectsAnEnvironmentFileInsideThePublicDirectory(): void
    {
        $pdo = AdminTestDatabase::create();
        $publicEnv = $this->root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($publicEnv, "APP_NAME=ClipForge\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The environment file must be the private project-root .env file.');
        $this->provisioner($pdo)->provision($publicEnv);
    }

    private function provisioner(\PDO $pdo): ApplicationEncryptionKeyProvisioner
    {
        try {
            return new ApplicationEncryptionKeyProvisioner($pdo, $this->root);
        } catch (\Error $error) {
            self::fail('ApplicationEncryptionKeyProvisioner is not implemented yet.');
        }
    }
}
