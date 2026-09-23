<?php

declare(strict_types=1);

namespace App\Gemini;

use InvalidArgumentException;

final class CurlGeminiTransport implements GeminiTransport
{
    private const REQUEST_BODY_LIMIT_BYTES = 10485760;
    private object $curl;

    public function __construct(?object $curlOperations = null)
    {
        $this->curl = $curlOperations ?? $this->nativeCurlOperations();
        foreach (['available', 'init', 'setOptions', 'execute', 'status', 'errno', 'close'] as $method) {
            if (!method_exists($this->curl, $method)) {
                throw new InvalidArgumentException('The cURL operations adapter is invalid.');
            }
        }
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        $this->validateRequest($method, $url, $headers, $body, $timeoutSeconds, $responseLimitBytes, false);

        return $this->perform(
            $method,
            $url,
            $headers,
            $body,
            $timeoutSeconds,
            $responseLimitBytes,
            null,
            null
        );
    }

    public function upload(
        string $url,
        array $headers,
        string $absolutePath,
        int $sizeBytes,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        $this->validateRequest('POST', $url, $headers, null, $timeoutSeconds, $responseLimitBytes, true);
        clearstatcache(true, $absolutePath);
        $actualSize = @filesize($absolutePath);
        if (!$this->isAbsolutePath($absolutePath)
            || $sizeBytes < 1
            || !is_file($absolutePath)
            || !is_readable($absolutePath)
            || !is_int($actualSize)
            || $actualSize !== $sizeBytes
        ) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        $stream = @fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw GeminiException::withCode('ai_unavailable');
        }

        try {
            return $this->perform(
                'POST',
                $url,
                $headers,
                null,
                $timeoutSeconds,
                $responseLimitBytes,
                $stream,
                $sizeBytes
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param resource|null $inputStream
     */
    private function perform(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $responseLimitBytes,
        $inputStream,
        ?int $uploadSize
    ): GeminiHttpResponse {
        if (!$this->curl->available()) {
            throw GeminiException::withCode('ai_unavailable');
        }

        try {
            $handle = $this->curl->init($url);
        } catch (\Throwable $exception) {
            throw GeminiException::withCode('ai_unavailable');
        }
        if ($handle === false || $handle === null) {
            throw GeminiException::withCode('ai_unavailable');
        }

        $responseBody = '';
        $responseHeaders = [];
        $bodyBytes = 0;
        $headerBytes = 0;
        $uploadedBytes = 0;
        $callbackFailure = null;

        $writeCallback = static function ($curlHandle, string $chunk) use (
            &$responseBody,
            &$bodyBytes,
            &$callbackFailure,
            $responseLimitBytes
        ): int {
            $length = strlen($chunk);
            if ($length > $responseLimitBytes - $bodyBytes) {
                $callbackFailure = GeminiException::withCode('ai_provider_rejected');
                return 0;
            }
            $responseBody .= $chunk;
            $bodyBytes += $length;

            return $length;
        };

        $headerCallback = static function ($curlHandle, string $line) use (
            &$responseHeaders,
            &$headerBytes,
            &$callbackFailure,
            $responseLimitBytes
        ): int {
            $length = strlen($line);
            if ($length > $responseLimitBytes - $headerBytes) {
                $callbackFailure = GeminiException::withCode('ai_provider_rejected');
                return 0;
            }
            $headerBytes += $length;

            if (preg_match('#\AHTTP/[0-9.]+\s+[0-9]{3}(?:\s|\z)#i', $line) === 1) {
                $responseHeaders = [];
                return $length;
            }
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '') {
                return $length;
            }
            if (!str_contains($trimmed, ':')) {
                $callbackFailure = GeminiException::withCode('ai_provider_rejected');
                return 0;
            }
            [$name, $value] = explode(':', $trimmed, 2);
            $name = trim($name);
            $value = trim($value, " \t");
            if (preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name) !== 1
                || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
            ) {
                $callbackFailure = GeminiException::withCode('ai_provider_rejected');
                return 0;
            }
            $responseHeaders[strtolower($name)][] = $value;

            return $length;
        };

        $readCallback = static function ($curlHandle, $stream, int $length) use (
            &$uploadedBytes,
            &$callbackFailure,
            $uploadSize
        ): string {
            if ($uploadSize === null || $uploadedBytes >= $uploadSize) {
                return '';
            }
            $remaining = $uploadSize - $uploadedBytes;
            $chunk = fread($stream, min($length, $remaining));
            if ($chunk === false) {
                $callbackFailure = GeminiException::withCode('ai_provider_rejected');
                return '';
            }
            $uploadedBytes += strlen($chunk);

            return $chunk;
        };

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPPROXYTUNNEL => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => $headerCallback,
            CURLOPT_WRITEFUNCTION => $writeCallback,
            CURLOPT_HTTPHEADER => $this->headerLines($headers),
            CURLOPT_USERAGENT => 'ClipForge-Gemini-Worker/1.0',
            CURLOPT_VERBOSE => false,
            CURLOPT_FAILONERROR => false,
            CURLOPT_NOSIGNAL => true,
        ];

        if ($inputStream !== null && $uploadSize !== null) {
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $inputStream;
            $options[CURLOPT_INFILESIZE] = $uploadSize;
            $options[CURLOPT_READFUNCTION] = $readCallback;
        } elseif ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        }

        try {
            if (!$this->curl->setOptions($handle, $options)) {
                throw GeminiException::withCode('ai_unavailable');
            }
            $executed = $this->curl->execute($handle);
            $status = $this->curl->status($handle);
            $errno = $this->curl->errno($handle);
        } catch (GeminiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw GeminiException::withCode('ai_unavailable', ['failure_kind'=>'internal']);
        } finally {
            try {
                $this->curl->close($handle);
            } catch (\Throwable $exception) {
                // Closing failures cannot safely expose provider or cURL details.
            }
        }

        if ($callbackFailure instanceof GeminiException) {
            throw $callbackFailure;
        }
        if ($executed === false) {
            throw GeminiException::withCode(
                $errno === CURLE_OPERATION_TIMEDOUT ? 'ai_timeout' : 'ai_unavailable',
                ['failure_kind'=>'transport','curl_errno'=>$errno]
            );
        }
        if ($status < 100 || $status > 599) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
        if ($uploadSize !== null) {
            $streamStatus = is_resource($inputStream) ? @fstat($inputStream) : false;
            if ($uploadedBytes !== $uploadSize
                || !is_array($streamStatus)
                || !isset($streamStatus['size'])
                || (int) $streamStatus['size'] !== $uploadSize
            ) {
                throw GeminiException::withCode('ai_provider_rejected');
            }
        }

        return new GeminiHttpResponse($status, $responseHeaders, $responseBody);
    }

    /** @param array<string, string> $headers */
    private function validateRequest(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $responseLimitBytes,
        bool $allowQuery
    ): void {
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)
            || !$this->isSafeProviderUrl($url, $allowQuery)
            || $timeoutSeconds < 1
            || $responseLimitBytes < 1
            || ($body !== null && strlen($body) > self::REQUEST_BODY_LIMIT_BYTES)
            || ($method !== 'POST' && $body !== null)
        ) {
            throw GeminiException::withCode('ai_provider_rejected');
        }

        if (count($headers) > 64) {
            throw GeminiException::withCode('ai_provider_rejected');
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name)
                || !is_string($value)
                || strlen($name) > 128
                || strlen($value) > 8192
                || preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw GeminiException::withCode('ai_provider_rejected');
            }
        }
    }

    private function isSafeProviderUrl(string $url, bool $allowQuery): bool
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'generativelanguage.googleapis.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (!$allowQuery && isset($parts['query']))
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            return false;
        }

        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    private function nativeCurlOperations(): object
    {
        return new class {
            public function available(): bool
            {
                return function_exists('curl_init');
            }

            public function init(string $url)
            {
                return curl_init($url);
            }

            public function setOptions($handle, array $options): bool
            {
                return curl_setopt_array($handle, $options);
            }

            public function execute($handle)
            {
                return curl_exec($handle);
            }

            public function status($handle): int
            {
                return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            }

            public function errno($handle): int
            {
                return curl_errno($handle);
            }

            public function close($handle): void
            {
                // curl_close() é no-op desde o PHP 8.0 e gera aviso de depreciação no PHP 8.5+;
                // o CurlHandle é fechado automaticamente via garbage collection.
            }
        };
    }
}
