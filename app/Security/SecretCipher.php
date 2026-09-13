<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class SecretCipher
{
    private const VERSION = 'v1';
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'clipforge:gemini-api-key:v1';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    private string $key;

    public function __construct(string $encodedMasterKey)
    {
        $key = base64_decode(trim($encodedMasterKey), true);
        if (!function_exists('openssl_get_cipher_methods')
            || !function_exists('openssl_encrypt')
            || !function_exists('openssl_decrypt')
            || !is_string($key)
            || strlen($key) !== 32
            || !in_array(self::CIPHER, openssl_get_cipher_methods(), true)
        ) {
            throw new RuntimeException('Application encryption key is invalid.');
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext, string $aad = self::AAD): string
    {
        $this->validateContext($aad);
        if ($plaintext === '') {
            throw new RuntimeException('Secret value is invalid.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_BYTES
        );
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Secret could not be encrypted.');
        }

        return self::VERSION . '.' . base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $envelope, string $aad = self::AAD): string
    {
        $this->validateContext($aad);
        $prefix = self::VERSION . '.';
        if (!str_starts_with($envelope, $prefix)) {
            throw new RuntimeException('Encrypted secret is invalid.');
        }

        $payload = base64_decode(substr($envelope, strlen($prefix)), true);
        if (!is_string($payload) || strlen($payload) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Encrypted secret is invalid.');
        }

        $nonce = substr($payload, 0, self::NONCE_BYTES);
        $tag = substr($payload, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($payload, self::NONCE_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new RuntimeException('Encrypted secret is invalid.');
        }

        return $plaintext;
    }

    private function validateContext(string $aad): void
    {
        if ($aad === '' || strlen($aad) > 128 || preg_match('/^[a-zA-Z0-9:._-]+$/D', $aad) !== 1) {
            throw new RuntimeException('Secret context is invalid.');
        }
    }
}
