<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ClipLibraryController;
use App\Core\Request;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ClipLibraryControllerTest extends TestCase
{
    private string $viewRoot;

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 42];
        $this->viewRoot = sys_get_temp_dir() . '/clip-library-controller-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->viewRoot . '/clips', 0700, true));
        self::assertNotFalse(file_put_contents(
            $this->viewRoot . '/clips/index.php',
            '<?php echo json_encode(compact("title", "user", "library"), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);'
        ));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (isset($this->viewRoot)) {
            @unlink($this->viewRoot . '/clips/index.php');
            @rmdir($this->viewRoot . '/clips');
            @rmdir($this->viewRoot);
        }
    }

    public function testIndexUsesOnlySessionOwnerAndPassesNormalizedContractWithPrivateCaching(): void
    {
        $seen = [];
        $controller = new ClipLibraryController(
            new View($this->viewRoot),
            static function (int $userId, string $filter, int $page, int $perPage) use (&$seen): array {
                $seen = [$userId, $filter, $page, $perPage];

                return self::library($filter, $page, $perPage);
            },
            static fn (int $userId): array => [
                'id' => $userId,
                'name' => 'Owner',
                'email' => 'owner@example.test',
                'credits' => 7,
                'plan_name' => 'Free',
                'monthly_minutes' => 60,
                'status' => 'active',
                'password_hash' => 'must-not-reach-view',
            ]
        );

        $response = $controller->index(Request::fake(
            'GET',
            '/clips?filter=completed&page=3&user_id=999999'
        ));
        $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([42, 'completed', 3, 24], $seen);
        self::assertSame(200, $response->status());
        self::assertSame('private, no-store', $response->header('Cache-Control'));
        self::assertSame('Clipes', $data['title']);
        self::assertSame(42, $data['user']['id']);
        self::assertArrayNotHasKey('password_hash', $data['user']);
        self::assertSame('completed', $data['library']['filter']);
        self::assertSame(3, $data['library']['page']);
    }

    /** @dataProvider adversarialQueryProvider */
    public function testIndexDefaultsAdversarialFilterAndPageWithoutWarnings(mixed $filter, mixed $page): void
    {
        $seen = [];
        $controller = new ClipLibraryController(
            new View($this->viewRoot),
            static function (int $userId, string $safeFilter, int $safePage, int $perPage) use (&$seen): array {
                $seen = [$userId, $safeFilter, $safePage, $perPage];

                return self::library($safeFilter, $safePage, $perPage);
            }
        );
        $request = new Request('GET', '/clips', ['filter' => $filter, 'page' => $page, 'user_id' => 999999]);

        $response = $controller->index($request);

        self::assertSame(200, $response->status());
        self::assertSame([42, 'recent', 1, 24], $seen);
        self::assertSame('private, no-store', $response->header('Cache-Control'));
    }

    /** @return array<string,array{mixed,mixed}> */
    public function adversarialQueryProvider(): array
    {
        return [
            'arrays' => [['completed'], ['2']],
            'zero and numeric' => ['unknown', 0],
            'negative' => [' completed ', '-2'],
            'decimal exponent' => ['COMPLETED', '1e3'],
            'decimal' => [null, '2.0'],
            'leading zero' => [true, '02'],
            'overflow' => [new \stdClass(), str_repeat('9', 100)],
        ];
    }

    public function testGuestRedirectsBeforeLibraryOrProfileLookup(): void
    {
        $_SESSION = [];
        $lookups = 0;
        $controller = new ClipLibraryController(
            new View($this->viewRoot),
            static function () use (&$lookups): array {
                ++$lookups;

                return [];
            },
            static function () use (&$lookups): array {
                ++$lookups;

                return [];
            }
        );

        $response = $controller->index(Request::fake('GET', '/clips?filter=completed&page=2'));

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $lookups);
    }

    /** @return array{items:list<array<string,mixed>>,filter:string,page:int,per_page:int,total:int,last_page:int} */
    private static function library(string $filter, int $page, int $perPage): array
    {
        return [
            'items' => [],
            'filter' => $filter,
            'page' => $page,
            'per_page' => $perPage,
            'total' => 0,
            'last_page' => 1,
        ];
    }
}
