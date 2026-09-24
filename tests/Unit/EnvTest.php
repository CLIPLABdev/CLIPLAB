<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testLoadsQuotedAndPlainValues(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertNotFalse($file);
        file_put_contents($file, "APP_NAME=ClipLab\nAPP_ENV=\"testing\"\n");

        Env::load($file);

        self::assertSame('ClipLab', Env::get('APP_NAME'));
        self::assertSame('testing', Env::get('APP_ENV'));

        unlink($file);
    }

    public function testReturnsDefaultForMissingEnvironmentValue(): void
    {
        self::assertSame('fallback', Env::get('MISSING_ENV_VALUE', 'fallback'));
    }

    public function testReturnsDefaultForMissingConfigurationValue(): void
    {
        self::assertSame('fallback', Config::get('missing.value', 'fallback'));
    }

    public function testEscapesHtmlAndHandlesNull(): void
    {
        self::assertSame('&lt;script&gt;&quot;&amp;&#039;&lt;/script&gt;', e('<script>"&\'</script>'));
        self::assertSame('', e(null));
    }
}
