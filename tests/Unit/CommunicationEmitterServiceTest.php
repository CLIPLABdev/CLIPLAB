<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Communications\CommunicationEmitterService;
use App\Communications\CommunicationEventCatalog;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommunicationEmitterServiceTest extends TestCase
{
    private PDO $pdo;
    private SecretCipher $cipher;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO users (id, email) VALUES (17, 'ana@example.test')");
        $this->pdo->exec('CREATE TABLE communication_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, event TEXT NOT NULL, category TEXT NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL, dedupe_key TEXT NOT NULL, read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, dedupe_key))');
        $this->pdo->exec("CREATE TABLE communication_email_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, recipient TEXT NOT NULL, event TEXT NOT NULL, category TEXT NOT NULL, payload_ciphertext TEXT NOT NULL, dedupe_key TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, leased_until DATETIME NULL, lease_token_hash TEXT NULL, last_error_code TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $this->cipher = new SecretCipher(base64_encode(str_repeat('k', 32)));
    }

    public function testEmitEncryptsSensitiveEmailPayloadAndLeavesCallerTransactionOpen(): void
    {
        $service = new CommunicationEmitterService($this->pdo, $this->cipher, new CommunicationEventCatalog());
        $this->pdo->beginTransaction();

        $service->emit(17, 'auth.password_reset', ['nome_usuario' => 'Ana', 'link_recuperacao' => 'https://app.example.test/redefinir?token=private-token'], 'reset:17:1');

        self::assertTrue($this->pdo->inTransaction());
        $outbox = $this->pdo->query('SELECT recipient, payload_ciphertext, status FROM communication_email_outbox')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ana@example.test', $outbox['recipient']);
        self::assertSame('pending', $outbox['status']);
        self::assertStringNotContainsString('private-token', $outbox['payload_ciphertext']);
        self::assertSame(['event' => 'auth.password_reset', 'variables' => ['nome_usuario' => 'Ana', 'link_recuperacao' => 'https://app.example.test/redefinir?token=private-token']], json_decode($this->cipher->decrypt($outbox['payload_ciphertext'], 'clipforge:communications:v1'), true, 32, JSON_THROW_ON_ERROR));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM communication_notifications')->fetchColumn());
        $this->pdo->rollBack();
    }

    public function testDuplicateEmissionCreatesOneNotificationAndOneOutboxMessage(): void
    {
        $service = new CommunicationEmitterService($this->pdo, $this->cipher, new CommunicationEventCatalog());

        foreach ([1, 2] as $attempt) {
            $service->emit(17, 'account.password_changed', ['nome_usuario' => 'Ana'], 'password-changed:17:8');
        }

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM communication_notifications')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
    }
}
