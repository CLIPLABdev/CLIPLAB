<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\SecretCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretCipherTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = base64_encode(str_repeat("\x2a", 32));
    }

    public function testRoundTripUsesRandomAuthenticatedEncryption(): void
    {
        $cipher = new SecretCipher($this->key);

        $first = $cipher->encrypt('test-secret-value');
        $second = $cipher->encrypt('test-secret-value');

        self::assertNotSame($first, $second);
        self::assertSame('test-secret-value', $cipher->decrypt($first));
        self::assertSame('test-secret-value', $cipher->decrypt($second));
        self::assertStringNotContainsString('test-secret-value', $first . $second);
    }

    public function testTamperingFailsWithoutLeakingPlaintext(): void
    {
        $cipher = new SecretCipher($this->key);
        $encrypted = $cipher->encrypt('secret-never-in-error');
        $tampered = substr($encrypted, 0, -2) . 'AA';

        try {
            $cipher->decrypt($tampered);
            self::fail('Tampered ciphertext was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('Encrypted secret is invalid.', $exception->getMessage());
            self::assertStringNotContainsString('secret-never-in-error', $exception->getMessage());
        }
    }

    public function testDomainContextCannotBeReusedForGeminiOrAnotherModule(): void
    {
        $cipher = new SecretCipher($this->key);
        $encrypted = $cipher->encrypt('private-token', 'clipforge:communications:v1');
        self::assertSame('private-token', $cipher->decrypt($encrypted, 'clipforge:communications:v1'));

        foreach ([null, 'clipforge:billing:v1'] as $context) {
            try {
                $context === null ? $cipher->decrypt($encrypted) : $cipher->decrypt($encrypted, $context);
                self::fail('A secret was accepted in the wrong domain.');
            } catch (RuntimeException $exception) {
                self::assertSame('Encrypted secret is invalid.', $exception->getMessage());
            }
        }
    }

    public function testDefaultGeminiContextRemainsCompatible(): void
    {
        $cipher = new SecretCipher($this->key);
        $nonce = str_repeat('n', 12);
        $tag = '';
        $payload = openssl_encrypt('legacy-secret', 'aes-256-gcm', base64_decode($this->key), OPENSSL_RAW_DATA, $nonce, $tag, 'clipforge:gemini-api-key:v1', 16);
        self::assertSame('legacy-secret', $cipher->decrypt('v1.' . base64_encode($nonce . $tag . $payload)));
    }

    public function testEmptyContextIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Secret context is invalid.');
        (new SecretCipher($this->key))->encrypt('secret', '');
    }

    public function testInvalidMasterKeyIsRejectedWithoutEchoingIt(): void
    {
        try {
            new SecretCipher('bad-master-key');
            self::fail('Invalid master key was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('Application encryption key is invalid.', $exception->getMessage());
            self::assertStringNotContainsString('bad-master-key', $exception->getMessage());
        }
    }
}
