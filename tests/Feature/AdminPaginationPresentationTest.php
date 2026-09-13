<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class AdminPaginationPresentationTest extends TestCase
{
    public function testUserPaginationRetainsItsValidatedFilters(): void
    {
        $_SESSION = ['user_id' => 1, '_csrf' => str_repeat('a', 64)];

        $html = (new View())->render('admin.users', [
            'title' => 'Usuários', 'admin' => ['name' => 'Admin', 'email' => 'admin@example.test'], 'flash' => null,
            'plans' => [['id' => 3, 'name' => 'Pro']],
            'page' => ['items' => [], 'filters' => ['q' => 'ana silva', 'status' => 'suspended', 'plan' => '3'], 'page' => 2, 'last_page' => 3, 'total' => 51],
        ])->body();

        self::assertStringContainsString('/admin/usuarios?q=ana%20silva&amp;status=suspended&amp;plan=3&amp;page=1', $html);
        self::assertStringContainsString('/admin/usuarios?q=ana%20silva&amp;status=suspended&amp;plan=3&amp;page=3', $html);
    }
}
