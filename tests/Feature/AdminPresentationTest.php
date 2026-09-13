<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class AdminPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 1, '_csrf' => str_repeat('a', 64)];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testUsersPageEscapesRecordsHasNoPrivateMediaAndWorksWithoutJavascript(): void
    {
        $html = (new View())->render('admin.users', [
            'title' => 'Usuários',
            'admin' => ['id' => 1, 'name' => 'Admin <root>', 'email' => 'admin@example.test'],
            'page' => [
                'items' => [[
                    'id' => 7, 'name' => '<script>alert(1)</script>', 'email' => 'u@example.test',
                    'role' => 'user', 'status' => 'active', 'plan_id' => 1, 'plan_name' => 'Free',
                    'credits' => 10, 'created_at' => '2026-09-06 10:00:00',
                    'object_key' => 'private/foreign.mp4', 'api_key' => 'foreign-secret',
                ]],
                'filters' => ['q' => '', 'status' => 'all', 'plan' => ''],
                'page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1,
            ],
            'plans' => [['id' => 1, 'name' => 'Free', 'is_active' => 1]],
            'flash' => null,
        ])->body();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('foreign-secret', $html);
        self::assertStringNotContainsString('private/foreign.mp4', $html);
        self::assertStringNotContainsString('<video', strtolower($html));
        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('25 por página', $html);
    }

    public function testAdminCssIsResponsiveKeyboardVisibleAndReallyHidesHiddenContent(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/admin.css');

        self::assertMatchesRegularExpression('/\.admin-shell\s+\[hidden\]\s*\{[^}]*display\s*:\s*none\s*!important/s', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('@media (max-width: 768px)', $css);
        self::assertStringContainsString('@media (max-width: 420px)', $css);
        self::assertStringContainsString('overflow-wrap: anywhere', $css);
        self::assertStringContainsString('min-width: 0', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function testGeminiPageExplainsEncryptionReadinessAndTestsOnlySavedConfiguration(): void
    {
        $html = (new View())->render('admin.gemini', [
            'title' => 'Configuração Gemini',
            'admin' => ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            'settings' => [
                'model' => 'gemini-test',
                'api_key_mask' => '•••1234',
                'has_database_key' => false,
                'has_effective_key' => true,
                'source' => 'environment',
                'configuration_error' => false,
                'encryption_ready' => false,
                'last_test_status' => 'untested',
                'last_tested_at' => null,
            ],
            'flash' => null,
        ])->body();

        self::assertStringContainsString('APP_ENCRYPTION_KEY', $html);
        self::assertStringContainsString('generate-encryption-key.php', $html);
        self::assertStringContainsString('configuração efetiva já salva', $html);
        self::assertStringContainsString('data-admin-gemini-save', $html);
        self::assertStringContainsString('data-admin-gemini-test', $html);
        self::assertMatchesRegularExpression('/data-admin-gemini-test-button[^>]*disabled|disabled[^>]*data-admin-gemini-test-button/', $html);
        self::assertStringContainsString('/assets/js/admin-gemini.js', $html);
    }

    public function testGeminiPageRequestsRecoveryInsteadOfGenerationWhenCiphertextAlreadyExists(): void
    {
        $html = (new View())->render('admin.gemini', [
            'title' => 'Configuração Gemini',
            'admin' => ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            'settings' => [
                'model' => 'gemini-test', 'api_key_mask' => 'Configurada, mas indisponível',
                'has_database_key' => true, 'has_effective_key' => true, 'source' => 'database',
                'configuration_error' => true, 'encryption_ready' => true,
                'last_test_status' => 'untested', 'last_tested_at' => null,
            ],
            'flash' => null,
        ])->body();

        self::assertStringContainsString('Restaure a APP_ENCRYPTION_KEY original', $html);
        self::assertStringNotContainsString('execute <code>php bin/generate-encryption-key.php</code>', $html);
    }
}
