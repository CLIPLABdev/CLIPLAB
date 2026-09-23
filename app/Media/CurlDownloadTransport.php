<?php

declare(strict_types=1);

namespace App\Media;

use App\Exceptions\MediaValidationException;

final class CurlDownloadTransport implements DownloadTransport
{
    /** @param callable(string): void $onChunk */
    public function download(DownloadRequest $request, callable $onChunk): DownloadResponse
    {
        if (!function_exists('curl_init')) {
            throw MediaValidationException::withCode('remote_download_unavailable');
        }
        $handle = curl_init($request->url()->url());
        if ($handle === false) {
            throw MediaValidationException::withCode('remote_download_failed');
        }

        $headers = [];
        $failure = null;
        $write = static function ($curl, string $chunk) use ($onChunk, &$failure): int {
            try {
                $onChunk($chunk);
                return strlen($chunk);
            } catch (MediaValidationException $exception) {
                $failure = $exception;
                return 0;
            } catch (\Throwable $exception) {
                $failure = MediaValidationException::withCode('storage_write_failed');
                return 0;
            }
        };
        $header = static function ($curl, string $line) use (&$headers): int {
            $length = strlen($line);
            if (preg_match('#^HTTP/[0-9.]+\\s+#i', $line) === 1) {
                $headers = [];
                return $length;
            }
            $trimmed = trim($line);
            if ($trimmed === '' || !str_contains($trimmed, ':')) {
                return $length;
            }
            [$name, $value] = explode(':', $trimmed, 2);
            $name = strtolower(trim($name));
            if ($name === '') {
                return $length;
            }
            $value = trim($value);
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ',' . $value : $value;
            return $length;
        };

        try {
            if (!curl_setopt_array($handle, $this->optionsFor($request, $write, $header))) {
                throw MediaValidationException::withCode('remote_download_failed');
            }
            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($handle);
        } finally {
            // curl_close() é no-op desde o PHP 8.0 e gera aviso de depreciação
            // no PHP 8.5+; o CurlHandle é liberado automaticamente pelo garbage collector.
        }
        if ($failure instanceof MediaValidationException) {
            throw $failure;
        }
        if ($result === false) {
            throw MediaValidationException::withCode($error === CURLE_OPERATION_TIMEDOUT ? 'remote_timeout' : 'remote_download_failed');
        }
        return new DownloadResponse($status, $headers);
    }

    /**
     * The option set is deliberately exposed for deterministic unit coverage.
     * @param callable $writeCallback cURL write callback
     * @param callable $headerCallback cURL header callback
     * @return array<int, mixed>
     */
    public function optionsFor(DownloadRequest $request, callable $writeCallback, callable $headerCallback): array
    {
        $parts = parse_url($request->url()->url());
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : 443;
        $resolveAddresses = [];
        foreach ($request->url()->addresses() as $address) {
            $resolveAddresses[] = str_contains($address, ':') ? '[' . $address . ']' : $address;
        }
        // Repeated host:port entries overwrite each other in cURL's DNS cache.
        $resolve = [$request->url()->host() . ':' . $port . ':' . implode(',', $resolveAddresses)];
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPPROXYTUNNEL => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $request->timeoutSeconds(),
            CURLOPT_TIMEOUT => $request->timeoutSeconds(),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => $headerCallback,
            CURLOPT_WRITEFUNCTION => $writeCallback,
            CURLOPT_USERAGENT => 'Clipforge-Media-Importer/1.0',
        ];
            $caBundle = getenv('CURL_CA_BUNDLE');
            if (is_string($caBundle) && is_file($caBundle) && is_readable($caBundle)) {
                $options[CURLOPT_CAINFO] = $caBundle;
            }
        // YouTube signs googlevideo URLs for a CDN endpoint and may reject a different pinned address.
        if (!str_ends_with($request->url()->host(), '.googlevideo.com') && $request->url()->host() !== 'googlevideo.com') {
            $options[CURLOPT_RESOLVE] = $resolve;
        }
        if ($request->rangeStart() !== null) {
            $options[CURLOPT_RANGE] = $request->rangeStart() . '-' . $request->rangeEnd();
        }
        return $options;
    }
}
