<?php

declare(strict_types=1);

namespace App\Communications;

use App\Contracts\CommunicationEmitter;
use App\Security\SecretCipher;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class CommunicationEmitterService implements CommunicationEmitter
{
    private const OUTBOX_AAD = 'clipforge:communications:v1';

    public function __construct(
        private PDO $pdo,
        private SecretCipher $cipher,
        private CommunicationEventCatalog $catalog
    ) {
    }

    public function emit(
        int $userId,
        string $event,
        array $variables,
        string $dedupeKey,
        ?string $recipient = null,
        array $channels = ['in_app', 'email'],
        ?DateTimeImmutable $availableAt = null
    ): void {
        if ($userId < 1 || preg_match('/^[a-z0-9][a-z0-9:._-]{0,190}$/D', $dedupeKey) !== 1) {
            throw new InvalidArgumentException('Communication identity is invalid.');
        }
        $definition = $this->catalog->definition($event, $variables);
        $channels = $this->effectiveChannels($channels, $definition['channels']);
        if ($channels === []) {
            throw new InvalidArgumentException('Communication channel is unavailable.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $preferences=new CommunicationInboxService($this->pdo);
            $email = null;
            if (in_array('email', $channels, true) && $preferences->emailEnabled($userId,$definition['category'])) {
                $email = $this->recipient($userId, $recipient);
            }
            if (in_array('in_app', $channels, true) && $preferences->inAppEnabled($userId,$definition['category'])) {
                $this->insertNotification($userId, $event, $definition['category'], $dedupeKey);
            }
            if ($email !== null) {
                $this->insertOutbox($userId, $email, $event, $definition['category'], $definition['variables'], $dedupeKey, $availableAt);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelByDedupePrefix(int $userId, string $prefix): void
    {
        if ($userId < 1 || preg_match('/^[a-z0-9][a-z0-9:._-]{1,180}$/D', $prefix) !== 1 || !str_contains($prefix, ':')) {
            throw new InvalidArgumentException('Communication cancellation prefix is invalid.');
        }
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix) . '%';
        $statement = $this->pdo->prepare(
            "UPDATE communication_email_outbox SET status = 'cancelled', payload_ciphertext = NULL WHERE user_id = :user_id"
            . " AND dedupe_key LIKE :prefix ESCAPE '!' AND status IN ('pending', 'retry')"
        );
        $statement->execute(['user_id' => $userId, 'prefix' => $escaped]);
    }

    /** @param list<mixed> $requested @param list<string> $allowed @return list<string> */
    private function effectiveChannels(array $requested, array $allowed): array
    {
        $normalized = [];
        foreach ($requested as $channel) {
            if (!is_string($channel) || !in_array($channel, ['in_app', 'email'], true)) {
                throw new InvalidArgumentException('Communication channel is invalid.');
            }
            if (in_array($channel, $allowed, true) && !in_array($channel, $normalized, true)) {
                $normalized[] = $channel;
            }
        }
        return $normalized;
    }

    private function recipient(int $userId, ?string $recipient): string
    {
        if ($recipient === null) {
            $statement = $this->pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $recipient = $statement->fetchColumn();
        }
        $recipient = is_string($recipient) ? mb_strtolower(trim($recipient)) : '';
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || mb_strlen($recipient) > 254) {
            throw new InvalidArgumentException('Communication recipient is invalid.');
        }
        return $recipient;
    }

    private function insertNotification(int $userId, string $event, string $category, string $dedupeKey): void
    {
        [$title, $body] = $this->notificationCopy($category);
        $statement = $this->pdo->prepare('INSERT INTO communication_notifications (user_id, event, category, title, body, dedupe_key) VALUES (:user_id, :event, :category, :title, :body, :dedupe_key)');
        try {
            $statement->execute(['user_id' => $userId, 'event' => $event, 'category' => $category, 'title' => $title, 'body' => $body, 'dedupe_key' => $dedupeKey]);
        } catch (PDOException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }
        }
    }

    /** @param array<string,string> $variables */
    private function insertOutbox(int $userId, string $recipient, string $event, string $category, array $variables, string $dedupeKey, ?DateTimeImmutable $availableAt): void
    {
        $payload = json_encode(['event' => $event, 'variables' => $variables], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $statement = $this->pdo->prepare('INSERT INTO communication_email_outbox (user_id, recipient, event, category, payload_ciphertext, dedupe_key, status, available_at) VALUES (:user_id, :recipient, :event, :category, :payload, :dedupe_key, :status, :available_at)');
        try {
            $statement->execute([
            'user_id' => $userId,
            'recipient' => $recipient,
            'event' => $event,
            'category' => $category,
            'payload' => $this->cipher->encrypt($payload, self::OUTBOX_AAD),
            'dedupe_key' => $dedupeKey,
            'status' => 'pending',
            'available_at' => ($availableAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if (!$this->isUniqueViolation($exception)) {
                throw $exception;
            }
        }
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $info = $exception->errorInfo;
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return is_array($info) && (int) ($info[1] ?? 0) === 1062;
        }
        return str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }

    /** @return array{string,string} */
    private function notificationCopy(string $category): array
    {
        return match ($category) {
            'billing' => ['Atualização financeira', 'Há uma atualização financeira na sua conta.'],
            'processing' => ['Atualização do processamento', 'Há uma atualização sobre o processamento do seu conteúdo.'],
            'usage' => ['Atualização de uso', 'Há uma atualização sobre os limites da sua conta.'],
            default => ['Atualização da conta', 'Há uma atualização importante na sua conta.'],
        };
    }
}
