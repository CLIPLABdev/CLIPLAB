<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\AdminRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class AdminNativeSearchTest extends TestCase
{
    /** @dataProvider searches */
    public function testSearchWorksWithNativeMysqlPlaceholders(string $method): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $query = 'absent-native-search-' . bin2hex(random_bytes(12));
        $page = (new AdminRepository($pdo))->{$method}(['q' => $query], 1);
        self::assertSame(0, $page['total']);
        self::assertSame([], $page['items']);
        self::assertSame($query, $page['filters']['q']);
    }

    public static function searches(): iterable
    {
        yield 'users' => ['paginateUsers'];
        yield 'projects' => ['paginateProjects'];
        yield 'videos' => ['paginateVideos'];
        yield 'credits' => ['paginateCredits'];
    }
}
