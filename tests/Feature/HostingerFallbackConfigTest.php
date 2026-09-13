<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class HostingerFallbackConfigTest extends TestCase
{
    public function testRootFallbackBlocksPrivatePathsBeforeRoutingRequestsToPublic(): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/.htaccess');

        self::assertLessThan(
            strpos($contents, '# Root document-root fallback'),
            strpos($contents, 'RewriteRule ^(?:app|bootstrap|config|database|storage|tests|vendor)(?:/|$)')
        );
        self::assertStringContainsString('RewriteRule ^assets/(.*)$ public/assets/$1 [L,NC]', $contents);
        self::assertStringContainsString('RewriteRule ^ public/index.php [QSA,L]', $contents);
        foreach (['application/javascript .mjs', 'application/wasm .wasm', 'application/octet-stream .tflite'] as $mime) {
            self::assertStringContainsString('AddType ' . $mime, $contents);
            self::assertStringContainsString('AddType ' . $mime, (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess'));
        }
    }
}
