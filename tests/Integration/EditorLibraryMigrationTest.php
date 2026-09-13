<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Media\Editor\EditorOptions;
use App\Media\StoredObject;
use App\Repositories\EditorLibraryRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class EditorLibraryMigrationTest extends TestCase
{
    public function testAdditiveMigrationAndRepositoryWorkWithRealMysqlForeignKeys(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/202609060045_create_editor_library.sql');
        $pdo->exec($sql); $pdo->exec($sql);
        $plan = $pdo->query('SELECT id FROM plans ORDER BY id LIMIT 1')->fetchColumn();
        self::assertNotFalse($plan);
        $pdo->beginTransaction();
        try {
            $users = [];
            foreach ([1, 2] as $i) {
                $pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')->execute(['Brand fixture', 'brand-' . bin2hex(random_bytes(8)) . '@example.test', 'x', $plan]);
                $users[] = (int) $pdo->lastInsertId();
            }
            $repo = new EditorLibraryRepository($pdo);
            $id = $repo->saveOwned($users[0], null, 'Persistido no MySQL', 'clean', EditorOptions::fromArray(['font_family' => 'Georgia']), '1:1');
            self::assertNull($repo->findOwned($id, $users[1]));
            self::assertSame('Georgia', (new EditorLibraryRepository($pdo))->snapshot($id, $users[0])['options']['font_family']);
            $repo->saveKit($users[0], EditorOptions::fromArray(['cta_text' => 'Meu canal']), '9:16', [$id]);
            self::assertSame([$id], $repo->kitForUser($users[0])['favorites']);
            $logo = $repo->addLogoOwned($users[0], new StoredObject('brand/test-' . bin2hex(random_bytes(8)) . '.png', 100, str_repeat('a', 64)), 1, 1);
            self::assertNull($repo->findLogoOwned($logo, $users[1]));
            self::assertSame(100, $repo->brandLogoBytes($users[0]));
            $columns = $pdo->query('SHOW COLUMNS FROM user_brand_logos')->fetchAll(PDO::FETCH_ASSOC);
            self::assertContains('user_id', array_column($columns, 'Field'));
        } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
}
