<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Request;
use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

final class DesignSystemTest extends TestCase
{
    public function testTextTokensMeetAaContrastOnTheirDarkSurfaces(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');

        self::assertGreaterThanOrEqual(4.5, $this->contrast($this->token($css, 'subtle'), $this->token($css, 'bg')));
        self::assertGreaterThanOrEqual(4.5, $this->contrast($this->token($css, 'muted'), $this->token($css, 'bg')));
        self::assertGreaterThanOrEqual(4.5, $this->contrast($this->token($css, 'muted'), $this->token($css, 'card')));
    }

    public function testMarketingPageReferencesVersionedLocalLucideBundle(): void
    {
        $_SESSION = [];
        $router = require dirname(__DIR__, 2) . '/routes/web.php';
        $response = $router->dispatch(Request::fake('GET', '/'));

        self::assertStringContainsString('/assets/vendor/lucide-0.468.0/lucide.min.js', $response->body());
        self::assertStringNotContainsString('unpkg.com', $response->body());
        self::assertFileExists(dirname(__DIR__, 2) . '/public/assets/vendor/lucide-0.468.0/lucide.min.js');
        self::assertFileExists(dirname(__DIR__, 2) . '/public/assets/vendor/lucide-0.468.0/LICENSE');
    }

    public function testContentSecurityPolicyDoesNotAuthorizeUnpkg(): void
    {
        $response = (new SecurityHeadersMiddleware())->handle(
            Request::fake('GET', '/'),
            static fn () => \App\Core\Response::html('ok')
        );

        self::assertStringNotContainsString('unpkg.com', (string) $response->header('Content-Security-Policy'));
    }

    private function token(string $css, string $name): string
    {
        preg_match('/--' . preg_quote($name, '/') . ':\s*(#[0-9a-fA-F]{6})/', $css, $match);
        self::assertArrayHasKey(1, $match);

        return $match[1];
    }

    private function contrast(string $foreground, string $background): float
    {
        $relativeLuminance = static function (string $hex): float {
            $channels = sscanf($hex, '#%02x%02x%02x');
            $linear = array_map(static function (int $channel): float {
                $value = $channel / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, $channels);

            return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
        };

        $first = $relativeLuminance($foreground);
        $second = $relativeLuminance($background);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }
}
