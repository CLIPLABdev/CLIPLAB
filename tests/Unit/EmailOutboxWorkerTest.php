<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Communications\EmailOutboxWorker;
use App\Contracts\Mailer;
use App\Security\SecretCipher;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailOutboxWorkerTest extends TestCase
{
    public function testWorkerReclaimsAnExpiredLease(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE communication_email_attempts (id INTEGER PRIMARY KEY, outbox_id INTEGER, attempt_number INTEGER, outcome TEXT, error_code TEXT, UNIQUE(outbox_id,attempt_number))');
        $pdo->exec("CREATE TABLE communication_email_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, recipient TEXT NOT NULL, event TEXT NOT NULL, category TEXT NOT NULL, payload_ciphertext TEXT NULL, dedupe_key TEXT NOT NULL UNIQUE, status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, leased_until DATETIME NULL, lease_token_hash TEXT NULL, last_error_code TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE communication_email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT NOT NULL, locale TEXT NOT NULL, subject_template TEXT NOT NULL, html_template TEXT NOT NULL, text_template TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(event, locale, version))");
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT,status TEXT)");$pdo->exec("CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY,user_id INTEGER,token_hash TEXT,expires_at TEXT,used_at TEXT)");$pdo->exec("INSERT INTO users VALUES(17,'ana@example.test','active')");$hash=hash('sha256','secret');$pdo->prepare("INSERT INTO password_reset_tokens VALUES(1,17,?,'2099-01-01',NULL)")->execute([$hash]);
        $pdo->exec("INSERT INTO communication_email_templates (event, locale, subject_template, html_template, text_template) VALUES ('auth.password_reset', 'pt-BR', 'Redefina {{nome_usuario}}', '<a href=\"{{link_recuperacao}}\">Redefinir</a>', 'x')");
        $cipher = new SecretCipher(base64_encode(str_repeat('k', 32)));
        $payload = $cipher->encrypt(json_encode(['event' => 'auth.password_reset', 'variables' => ['nome_usuario' => 'Ana', 'link_recuperacao' => 'https://app.example.test/redefinir?token=secret']], JSON_THROW_ON_ERROR), 'clipforge:communications:v1');
        $insert = $pdo->prepare("INSERT INTO communication_email_outbox (user_id, recipient, event, category, payload_ciphertext, dedupe_key, status, attempts, available_at, leased_until, lease_token_hash) VALUES (17, 'ana@example.test', 'auth.password_reset', 'account', :payload, :key, 'leased', 1, '2026-09-07 11:00:00', '2026-09-07 11:01:00', 'stale')");
        $insert->execute(['payload' => $payload,'key'=>'password-reset:17:'.$hash]);

        self::assertSame('sent', (new EmailOutboxWorker($pdo, $cipher, new WorkerCapturingMailer()))->processOne(new DateTimeImmutable('2026-09-07 12:00:00 UTC')));
    }
    public function testWorkerSendsClaimedMessageThenErasesItsEncryptedPayload(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE communication_email_attempts (id INTEGER PRIMARY KEY, outbox_id INTEGER, attempt_number INTEGER, outcome TEXT, error_code TEXT, UNIQUE(outbox_id,attempt_number))');
        $pdo->exec("CREATE TABLE communication_email_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, recipient TEXT NOT NULL, event TEXT NOT NULL, category TEXT NOT NULL, payload_ciphertext TEXT NULL, dedupe_key TEXT NOT NULL UNIQUE, status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, available_at DATETIME NOT NULL, leased_until DATETIME NULL, lease_token_hash TEXT NULL, last_error_code TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE communication_email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT NOT NULL, locale TEXT NOT NULL, subject_template TEXT NOT NULL, html_template TEXT NOT NULL, text_template TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(event, locale, version))");
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT,status TEXT)");$pdo->exec("CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY,user_id INTEGER,token_hash TEXT,expires_at TEXT,used_at TEXT)");$pdo->exec("INSERT INTO users VALUES(17,'ana@example.test','active')");$hash=hash('sha256','secret');$pdo->prepare("INSERT INTO password_reset_tokens VALUES(1,17,?,'2099-01-01',NULL)")->execute([$hash]);
        $pdo->exec("INSERT INTO communication_email_templates (event, locale, subject_template, html_template, text_template) VALUES ('auth.password_reset', 'pt-BR', 'Redefina sua senha, {{nome_usuario}}', '<a href=\"{{link_recuperacao}}\">Redefinir</a>', 'Redefinir: {{link_recuperacao}}')");
        $cipher = new SecretCipher(base64_encode(str_repeat('k', 32)));
        $payload = $cipher->encrypt(json_encode(['event' => 'auth.password_reset', 'variables' => ['nome_usuario' => 'Ana', 'link_recuperacao' => 'https://app.example.test/redefinir?token=secret']], JSON_THROW_ON_ERROR), 'clipforge:communications:v1');
        $insert = $pdo->prepare("INSERT INTO communication_email_outbox (user_id, recipient, event, category, payload_ciphertext, dedupe_key, status, available_at) VALUES (17, 'ana@example.test', 'auth.password_reset', 'account', :payload, :key, 'pending', '2026-09-07 11:00:00')");
        $insert->execute(['payload' => $payload,'key'=>'password-reset:17:'.$hash]);
        $mailer = new WorkerCapturingMailer();

        $result = (new EmailOutboxWorker($pdo, $cipher, $mailer))->processOne(new DateTimeImmutable('2026-09-07 12:00:00 UTC'));

        self::assertSame('sent', $result);
        self::assertSame(['ana@example.test', 'Redefina sua senha, Ana'], array_slice($mailer->sent[0],0,2));
        $document=new \DOMDocument();@$document->loadHTML($mailer->sent[0][2]);
        self::assertSame('Redefinir',$document->getElementsByTagName('a')->item(0)->textContent);
        self::assertSame('https://app.example.test/redefinir?token=secret',$document->getElementsByTagName('a')->item(0)->getAttribute('href'));
        self::assertSame(1,$document->getElementsByTagName('body')->length);
        self::assertSame(['sent', null], $pdo->query('SELECT status, payload_ciphertext FROM communication_email_outbox')->fetch(PDO::FETCH_NUM));
    }
}

final class WorkerCapturingMailer implements Mailer
{
    /** @var list<array{string,string,string}> */
    public array $sent = [];

    public function send(string $recipient, string $subject, string $html): void
    {
        $this->sent[] = [$recipient, $subject, $html];
    }
}
