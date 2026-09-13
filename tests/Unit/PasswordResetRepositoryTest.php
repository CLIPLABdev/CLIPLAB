<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\PasswordResetRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PasswordResetRepositoryTest extends TestCase
{
    public function testKeepsThePreviousActiveTokenWhenReplacementInsertFails(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('INSERT INTO users (id) VALUES (1), (2)');
        $pdo->exec("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (1, 'active-token', '2099-01-01 00:00:00')");
        $pdo->exec("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (2, 'conflicting-token', '2099-01-01 00:00:00')");

        $repository = new PasswordResetRepository($pdo);

        try {
            $repository->replaceForUser(1, 'conflicting-token', '2099-01-01 00:00:00');
            self::fail('The unique token hash must reject the replacement insert.');
        } catch (PDOException) {
        }

        $usedAt = $pdo->query("SELECT used_at FROM password_reset_tokens WHERE token_hash = 'active-token'")->fetchColumn();
        self::assertNull($usedAt);
    }
}
