<?php
declare(strict_types=1);
namespace Tests\Support;

use PDO;

trait EditorLibraryFixture
{
    private function libraryDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, status TEXT DEFAULT 'active'); INSERT INTO users (id) VALUES (1),(2);
            CREATE TABLE projects (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id)); INSERT INTO projects VALUES (10,1),(20,2);
            CREATE TABLE user_editor_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id), name TEXT, category TEXT, options_json TEXT, aspect_ratio TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE user_brand_kits (user_id INTEGER PRIMARY KEY REFERENCES users(id), options_json TEXT, aspect_ratio TEXT, favorites_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE user_brand_logos (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id), object_key TEXT UNIQUE, size_bytes INTEGER, sha256 TEXT, width INTEGER, height INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        return $pdo;
    }

    private function png(): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0))
            . $chunk('IDAT', gzcompress("\0\xff\xff\xff\xff")) . $chunk('IEND', '');
    }
}
