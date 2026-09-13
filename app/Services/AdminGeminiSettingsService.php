<?php

declare(strict_types=1);

namespace App\Services;

use App\Gemini\CurlGeminiTransport;
use App\Gemini\GeminiTransport;
use App\Repositories\SystemLogRepository;
use App\Security\SecretCipher;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminGeminiSettingsService
{
    private ?GeminiTransport $transport;
    private RateLimiter $limiter;
    private SystemLogRepository $logs;

    /** @param array<string,mixed> $baseline */
    public function __construct(
        private PDO $pdo,
        private array $baseline,
        private string $encryptionKey,
        ?GeminiTransport $transport = null,
        ?RateLimiter $limiter = null,
        ?SystemLogRepository $logs = null
    ) {
        $this->transport = $transport;
        $this->limiter = $limiter ?? new RateLimiter($pdo);
        $this->logs = $logs ?? new SystemLogRepository($pdo);
    }

    /** @return array<string,mixed> */
    public function effective(): array
    {
        $effective = $this->baseline;
        $effective['api_key'] = is_string($effective['api_key'] ?? null) ? trim($effective['api_key']) : '';
        $effective['model'] = is_string($effective['model'] ?? null) ? trim($effective['model']) : '';
        $row = $this->settings();
        if ($row === null) {
            return $effective;
        }

        $model = is_string($row['model'] ?? null) ? trim($row['model']) : '';
        if ($model !== '') {
            $this->assertModel($model);
            $effective['model'] = $model;
        }
        $encrypted = is_string($row['api_key_ciphertext'] ?? null) ? trim($row['api_key_ciphertext']) : '';
        if ($encrypted !== '') {
            $effective['api_key'] = $this->cipher()->decrypt($encrypted);
        }

        return $effective;
    }

    /** @return array<string,mixed> */
    public function publicState(): array
    {
        $row = $this->settings();
        $encrypted = is_string($row['api_key_ciphertext'] ?? null) ? trim($row['api_key_ciphertext']) : '';
        $environmentKey = is_string($this->baseline['api_key'] ?? null) ? trim($this->baseline['api_key']) : '';
        $mask = '';
        $configurationError = false;
        if ($encrypted !== '') {
            try {
                $mask = $this->mask($this->cipher()->decrypt($encrypted));
            } catch (Throwable) {
                $mask = 'Configurada, mas indisponível';
                $configurationError = true;
            }
        } elseif ($environmentKey !== '') {
            $mask = $this->mask($environmentKey);
        }

        return [
            'model' => is_string($row['model'] ?? null) && trim($row['model']) !== '' ? trim($row['model']) : trim((string) ($this->baseline['model'] ?? '')),
            'api_key_mask' => $mask,
            'has_database_key' => $encrypted !== '',
            'has_effective_key' => $encrypted !== '' || $environmentKey !== '',
            'encryption_ready' => $this->encryptionReady(),
            'source' => $encrypted !== '' ? 'database' : ($environmentKey !== '' ? 'environment' : 'missing'),
            'configuration_error' => $configurationError,
            'last_test_status' => is_string($row['last_test_status'] ?? null) ? $row['last_test_status'] : 'untested',
            'last_test_code' => is_string($row['last_test_code'] ?? null) ? $row['last_test_code'] : null,
            'last_tested_at' => is_string($row['last_tested_at'] ?? null) ? $row['last_tested_at'] : null,
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(int $actorId, array $input): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Administrator identifier is invalid.');
        }
        $model = is_string($input['model'] ?? null) ? trim($input['model']) : '';
        $this->assertModel($model);
        $secret = is_string($input['api_key'] ?? null) ? $input['api_key'] : '';
        if ($secret !== '' && !$this->isValidApiKey($secret)) {
            throw new InvalidArgumentException('Gemini API key is invalid.');
        }
        $clear = ($input['clear_api_key'] ?? false) === true;

        $this->transactional(function () use ($actorId, $model, $secret, $clear): void {
            $this->assertActor($actorId, true);
            $row = $this->settings(true);
            $encrypted = is_string($row['api_key_ciphertext'] ?? null) ? $row['api_key_ciphertext'] : null;
            if ($clear) {
                $encrypted = null;
            } elseif ($secret !== '') {
                $encrypted = $this->cipher()->encrypt($secret);
            }
            $this->upsert($encrypted, $model, $actorId);
            $this->logs->record('warning', 'admin.gemini_settings_updated', [
                'model' => $model,
                'source' => $encrypted !== null && $encrypted !== '' ? 'database' : 'environment',
            ], $actorId, 'gemini_settings', 1);
        });
    }

    /** @return array{ok:bool,code:string,message:string} */
    public function testConnection(int $actorId, string $subject): array
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Administrator identifier is invalid.');
        }
        $this->assertActor($actorId, false);
        if (!$this->limiter->hit('admin-gemini-connection-actor', (string) $actorId, 3, 300)
            || !$this->limiter->hit('admin-gemini-connection-source', trim($subject), 10, 300)
        ) {
            return ['ok' => false, 'code' => 'rate_limited', 'message' => 'Aguarde antes de testar novamente.'];
        }

        try {
            $config = $this->effective();
            $apiKey = trim((string) ($config['api_key'] ?? ''));
            $model = trim((string) ($config['model'] ?? ''));
            $this->assertModel($model);
            if (!$this->isValidApiKey($apiKey)) {
                throw new RuntimeException('Gemini API key is not configured.');
            }
            $body = json_encode([
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Responda apenas OK. Este é um teste sintético de configuração.']]]],
                'generationConfig' => ['maxOutputTokens' => 512, 'temperature' => 0],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = ($this->transport ??= new CurlGeminiTransport())->request(
                'POST',
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
                ['content-type' => 'application/json', 'x-goog-api-key' => $apiKey],
                $body,
                max(5, min(30, (int) ($config['http_timeout_seconds'] ?? 15))),
                max(4096, min(65536, (int) ($config['response_limit_bytes'] ?? 65536)))
            );
            if ($response->status() >= 200 && $response->status() < 300) {
                $result = $this->hasStoppedTextCandidate($response->body())
                    ? ['ok' => true, 'code' => 'connected', 'message' => 'Conexão com o Gemini confirmada.']
                    : ['ok' => false, 'code' => 'invalid_provider_response', 'message' => 'O Gemini respondeu, mas o teste não foi confirmado.'];
            } elseif (in_array($response->status(), [401, 403], true)) {
                $result = ['ok' => false, 'code' => 'credentials_rejected', 'message' => 'O Gemini recusou as credenciais configuradas.'];
            } elseif ($response->status() === 429) {
                $result = ['ok' => false, 'code' => 'provider_rate_limited', 'message' => 'O Gemini limitou temporariamente as solicitações.'];
            } else {
                $result = ['ok' => false, 'code' => 'provider_unavailable', 'message' => 'Não foi possível confirmar a conexão agora.'];
            }
        } catch (Throwable) {
            $result = ['ok' => false, 'code' => 'configuration_or_network_error', 'message' => 'Não foi possível confirmar a conexão agora.'];
        }

        $this->storeTestResult($actorId, $result['ok'], $result['code']);

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function settings(bool $locked = false): ?array
    {
        $sql = 'SELECT id, api_key_ciphertext, model, last_test_status, last_test_code, last_tested_at FROM gemini_settings WHERE id = 1 LIMIT 1';
        if ($locked && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function upsert(?string $encrypted, string $model, int $actorId): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql = 'INSERT INTO gemini_settings (id, api_key_ciphertext, model, updated_by) VALUES (1, :secret, :model, :actor)
                    ON DUPLICATE KEY UPDATE api_key_ciphertext = VALUES(api_key_ciphertext), model = VALUES(model), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO gemini_settings (id, api_key_ciphertext, model, updated_by) VALUES (1, :secret, :model, :actor)
                    ON CONFLICT(id) DO UPDATE SET api_key_ciphertext = excluded.api_key_ciphertext, model = excluded.model, updated_by = excluded.updated_by, updated_at = CURRENT_TIMESTAMP';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['secret' => $encrypted, 'model' => $model, 'actor' => $actorId]);
    }

    private function storeTestResult(int $actorId, bool $ok, string $code): void
    {
        try {
            $this->transactional(function () use ($actorId, $ok, $code): void {
                $row = $this->settings(true);
                $secret = is_array($row) && is_string($row['api_key_ciphertext'] ?? null) ? $row['api_key_ciphertext'] : null;
                $model = is_array($row) && is_string($row['model'] ?? null) && trim($row['model']) !== ''
                    ? trim($row['model'])
                    : trim((string) ($this->baseline['model'] ?? ''));
                $this->upsert($secret, $model, $actorId);
                $statement = $this->pdo->prepare(
                    'UPDATE gemini_settings SET last_test_status = :status, last_test_code = :code,
                            last_tested_at = CURRENT_TIMESTAMP, updated_by = :actor, updated_at = CURRENT_TIMESTAMP WHERE id = 1'
                );
                $statement->execute(['status' => $ok ? 'success' : 'failed', 'code' => $code, 'actor' => $actorId]);
                $this->logs->record('info', 'admin.gemini_connection_tested', ['model' => $model, 'result_code' => $code], $actorId, 'gemini_settings', 1);
            });
        } catch (Throwable) {
            // A health check never exposes persistence or provider internals to the browser.
        }
    }

    private function cipher(): SecretCipher
    {
        return new SecretCipher($this->encryptionKey);
    }

    private function encryptionReady(): bool
    {
        try {
            $this->cipher();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function assertActor(int $actorId, bool $locked): void
    {
        $sql = 'SELECT role, status FROM users WHERE id = :id LIMIT 1';
        if ($locked && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $actorId]);
        $actor = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($actor) || ($actor['role'] ?? null) !== 'admin' || ($actor['status'] ?? null) !== 'active') {
            throw new \DomainException('An active administrator is required.');
        }
    }

    private function assertModel(string $model): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $model) !== 1) {
            throw new InvalidArgumentException('Gemini model is invalid.');
        }
    }

    private function mask(string $secret): string
    {
        if (strlen($secret) <= 4) {
            return '••••';
        }
        $tail = mb_substr($secret, -4);

        return '••••' . $tail;
    }

    private function isValidApiKey(string $secret): bool
    {
        return preg_match('/\A[\x21-\x7E]{8,512}\z/D', $secret) === 1;
    }

    private function hasStoppedTextCandidate(string $body): bool
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($decoded['candidates'] ?? null)) {
            return false;
        }
        foreach ($decoded['candidates'] as $candidate) {
            if (!is_array($candidate) || ($candidate['finishReason'] ?? null) !== 'STOP') {
                continue;
            }
            $parts = $candidate['content']['parts'] ?? null;
            if (!is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function transactional(callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT admin_gemini_settings');
        }
        try {
            $result = $operation();
            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT admin_gemini_settings');
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$owns && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT admin_gemini_settings');
                $this->pdo->exec('RELEASE SAVEPOINT admin_gemini_settings');
            }
            throw $exception;
        }
    }
}
