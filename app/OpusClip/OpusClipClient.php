<?php

declare(strict_types=1);

namespace App\OpusClip;

use RuntimeException;

/**
 * Cliente isolado para a API da OpusClip (https://api.opus.pro).
 * Não depende de nada do resto do projeto — pode ser testado sozinho
 * antes de ser plugado no pipeline de jobs.
 */
final class OpusClipClient
{
    private const BASE_URL = 'https://api.opus.pro/api';

    public function __construct(private string $apiKey)
    {
        if (trim($apiKey) === '') {
            throw new RuntimeException('Chave da API OpusClip não configurada.');
        }
    }

    private static function allowInsecureSsl(): bool
    {
        $value = getenv('OPUS_CLIP_ALLOW_INSECURE_SSL');
        if ($value === false) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }

    private static function resolveCaBundle(): ?string
    {
        $candidates = [];

        $curlCainfo = ini_get('curl.cainfo');
        if (is_string($curlCainfo) && trim($curlCainfo) !== '') {
            $candidates[] = trim($curlCainfo);
        }

        $opensslCafile = ini_get('openssl.cafile');
        if (is_string($opensslCafile) && trim($opensslCafile) !== '') {
            $candidates[] = trim($opensslCafile);
        }

        $candidates[] = 'C:/Program Files/Common Files/SSL/certs/ca-bundle.crt';
        $candidates[] = 'C:/Program Files/Common Files/SSL/certs/ca-certificates.crt';
        $candidates[] = 'C:/Program Files (x86)/Common Files/SSL/certs/ca-bundle.crt';
        $candidates[] = 'C:/Windows/System32/certmgr/ca-bundle.crt';
        $candidates[] = 'C:/Windows/System32/certsrv/certenroll/ca-bundle.crt';

        $phpRoot = getenv('PHP_ROOT') ?: getenv('PHP_HOME');
        if (is_string($phpRoot) && $phpRoot !== '') {
            $candidates[] = rtrim($phpRoot, DIRECTORY_SEPARATOR) . '/extras/ssl/cacert.pem';
            $candidates[] = rtrim($phpRoot, DIRECTORY_SEPARATOR) . '/cacert.pem';
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function configureSslOptions($ch): void
    {
        $caBundle = self::resolveCaBundle();
        if (is_string($caBundle) && $caBundle !== '') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            return;
        }

        if (self::allowInsecureSsl()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    /**
     * Passo 1: gera um link de upload (Google Cloud Storage) e um uploadId.
     * @return array{url: string, uploadId: string}
     */
    public function createUploadLink(): array
    {
        $response = $this->request('POST', '/upload-links', [
            'video' => ['usecase' => 'LocalUpload'],
        ]);

        if (!isset($response['url'], $response['uploadId'])
            || !is_string($response['url']) || !is_string($response['uploadId'])
        ) {
            throw new RuntimeException('Resposta inesperada da OpusClip ao criar link de upload.');
        }

        return ['url' => $response['url'], 'uploadId' => $response['uploadId']];
    }

    /**
     * Passo 2: inicia a sessão de upload resumível (GCS) e retorna a URL
     * de destino onde o arquivo de vídeo deve ser enviado (PUT).
     */
    public function startResumableSession(string $uploadUrl, string $fileName, int $fileSizeBytes): string
    {
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'x-goog-resumable: start',
                'Content-Length: 0',
            ],
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        self::configureSslOptions($ch);
        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errNo !== 0 || !is_string($raw)) {
            throw new RuntimeException('Falha de rede ao iniciar upload resumível.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('OpusClip recusou o início do upload (HTTP ' . $status . ').');
        }
        if (preg_match('/^Location:\s*(\S+)/mi', $raw, $match) !== 1) {
            throw new RuntimeException('OpusClip não retornou a URL de destino do upload.');
        }

        return trim($match[1]);
    }

    /**
     * Passo 3: envia os bytes do vídeo para a URL retornada pela sessão resumível.
     */
    public function uploadFile(string $resumableUrl, string $absolutePath): void
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Arquivo de vídeo não encontrado ou sem permissão de leitura.');
        }
        $size = filesize($absolutePath);
        $handle = fopen($absolutePath, 'rb');
        if ($size === false || $handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo de vídeo.');
        }

        $ch = curl_init($resumableUrl);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $handle,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => ['Content-Type: video/mp4'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 0, // uploads podem ser grandes/demorados
        ]);
        self::configureSslOptions($ch);
        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($handle);

        if ($errNo !== 0 || $raw === false) {
            throw new RuntimeException('Falha de rede ao enviar o arquivo de vídeo.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('OpusClip recusou o arquivo enviado (HTTP ' . $status . ').');
        }
    }

    /**
     * Passo 4: cria o projeto de corte a partir do uploadId (ou de uma URL pública).
     * @param array<string, mixed> $curationPref Preferências opcionais (duração dos clipes, gênero, etc.)
     * @return array{projectId: string}
     */
    public function createProject(string $videoUrlOrUploadId, array $curationPref = []): array
    {
        $body = ['videoUrl' => $videoUrlOrUploadId];
        if ($curationPref !== []) {
            $body['curationPref'] = $curationPref;
        }

        $response = $this->request('POST', '/clip-projects', $body);

        $projectId = $response['id'] ?? $response['projectId'] ?? null;
        if (!is_string($projectId) && !is_int($projectId)) {
            throw new RuntimeException('Resposta inesperada da OpusClip ao criar o projeto.');
        }

        return ['projectId' => (string) $projectId];
    }

    /**
     * Passo 5: busca os clipes já gerados de um projeto.
     * @return list<array<string, mixed>>
     */
    public function getClipsForProject(string $projectId): array
    {
        $response = $this->request(
            'GET',
            '/exportable-clips?q=findByProjectId&projectId=' . urlencode($projectId)
        );

        // A API pode devolver a lista direto ou dentro de uma chave 'data'/'items'.
        if (isset($response['data']) && is_array($response['data'])) {
            return array_values($response['data']);
        }
        if (isset($response['items']) && is_array($response['items'])) {
            return array_values($response['items']);
        }
        if (array_is_list($response)) {
            return $response;
        }

        return [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE_URL . $path);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        self::configureSslOptions($ch);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errNo !== 0 || !is_string($raw)) {
            throw new RuntimeException('Falha de rede ao chamar a OpusClip: ' . $errMsg);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('OpusClip retornou erro HTTP ' . $status . ': ' . substr($raw, 0, 500));
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('OpusClip retornou uma resposta que não é JSON válido.');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
