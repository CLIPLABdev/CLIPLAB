<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

final class AdminTestDatabase
{
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            price_cents INTEGER NOT NULL DEFAULT 0,
            monthly_minutes INTEGER NOT NULL DEFAULT 0,
            credits INTEGER NOT NULL DEFAULT 0,
            features TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            plan_id INTEGER NOT NULL,
            credits INTEGER NOT NULL DEFAULT 0,
            role TEXT NOT NULL DEFAULT "user",
            status TEXT NOT NULL DEFAULT "active",
            archived_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (plan_id) REFERENCES plans(id)
        )');
        $pdo->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            source_filename TEXT NULL,
            status TEXT NOT NULL DEFAULT "draft",
            progress INTEGER NOT NULL DEFAULT 0,
            error_code TEXT NULL,
            error_message TEXT NULL,
            original_duration_seconds INTEGER NOT NULL DEFAULT 0,
            processed_duration_seconds INTEGER NOT NULL DEFAULT 0,
            storage_bytes INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )');
        $pdo->exec('CREATE TABLE project_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL UNIQUE,
            source_type TEXT NOT NULL,
            storage_disk TEXT NOT NULL DEFAULT "local",
            object_key TEXT NULL,
            original_name TEXT NULL,
            mime_type TEXT NULL,
            size_bytes INTEGER NOT NULL DEFAULT 0,
            sha256 TEXT NULL,
            source_url TEXT NULL,
            source_host TEXT NULL,
            width INTEGER NULL,
            height INTEGER NULL,
            duration_seconds INTEGER NULL,
            video_codec TEXT NULL,
            audio_codec TEXT NULL,
            has_audio INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "pending",
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id)
        )');
        $pdo->exec('CREATE TABLE clips (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, title TEXT NULL, status TEXT NOT NULL DEFAULT "draft", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE processing_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue_name TEXT NOT NULL DEFAULT "media",
            type TEXT NOT NULL,
            project_id INTEGER NOT NULL,
            payload_json TEXT NOT NULL,
            idempotency_key TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT "queued",
            progress INTEGER NOT NULL DEFAULT 0,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            worker_id TEXT NULL,
            lease_token_hash TEXT NULL,
            leased_until TEXT NULL,
            started_at TEXT NULL,
            finished_at TEXT NULL,
            last_error_code TEXT NULL,
            last_error_message TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id)
        )');
        $pdo->exec('CREATE TABLE credit_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            amount INTEGER NOT NULL,
            balance_after INTEGER NOT NULL,
            reference_type TEXT NULL,
            reference_id INTEGER NULL,
            description TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )');
        $pdo->exec('CREATE TABLE system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL,
            event_code TEXT NOT NULL,
            public_message TEXT NOT NULL,
            context_json TEXT NOT NULL,
            actor_id INTEGER NULL,
            target_type TEXT NULL,
            target_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE gemini_settings (
            id INTEGER PRIMARY KEY,
            api_key_ciphertext TEXT NULL,
            model TEXT NULL,
            last_test_status TEXT NOT NULL DEFAULT "untested",
            last_test_code TEXT NULL,
            last_tested_at TEXT NULL,
            updated_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rate_key TEXT NOT NULL,
            action TEXT NOT NULL,
            window_started_at TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(rate_key, action)
        )');
        $pdo->exec('CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE promotions (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL, cta_label TEXT NULL, cta_url TEXT NULL, image_url TEXT NULL, delivery_kind TEXT NOT NULL DEFAULT "banner", placement TEXT NOT NULL, audience TEXT NOT NULL, plan_id INTEGER NULL, user_id INTEGER NULL, starts_at TEXT NULL, ends_at TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        return $pdo;
    }

    /** @return array{plan_id:int,admin_id:int,user_id:int,other_admin_id:int} */
    public static function seed(PDO $pdo): array
    {
        $features = '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":104857600,"storage_bytes":1073741824}}';
        $insertPlan = $pdo->prepare('INSERT INTO plans (slug, name, price_cents, monthly_minutes, credits, features, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $insertPlan->execute(['free', 'Free', 0, 30, 10, $features]);
        $planId = (int) $pdo->lastInsertId();

        $insertUser = $pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insertUser->execute(['Admin', 'admin@example.test', password_hash('Strong-password-1', PASSWORD_DEFAULT), $planId, 0, 'admin', 'active']);
        $adminId = (int) $pdo->lastInsertId();
        $insertUser->execute(['Cliente', 'cliente@example.test', password_hash('Customer-password-1', PASSWORD_DEFAULT), $planId, 10, 'user', 'active']);
        $userId = (int) $pdo->lastInsertId();
        $insertUser->execute(['Outro admin', 'admin2@example.test', password_hash('Strong-password-2', PASSWORD_DEFAULT), $planId, 0, 'admin', 'active']);
        $otherAdminId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, 'credit', 10, 10, 'registration', 'Créditos iniciais']);

        return [
            'plan_id' => $planId,
            'admin_id' => $adminId,
            'user_id' => $userId,
            'other_admin_id' => $otherAdminId,
        ];
    }
}
