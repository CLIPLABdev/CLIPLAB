<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class WebUploadLimitCompositionTest extends TestCase
{
    public function testWebControllerReceivesTheEffectivePhpUploadLimit(): void
    {
        $capture = tempnam(sys_get_temp_dir(), 'web-upload-limit-');
        $prepend = tempnam(sys_get_temp_dir(), 'web-upload-prepend-');
        self::assertNotFalse($capture);
        self::assertNotFalse($prepend);
        $base = dirname(__DIR__, 2);
        $autoload = var_export($base . '/vendor/autoload.php', true);
        file_put_contents($prepend, "<?php\nnamespace { require {$autoload}; }\nnamespace App\\Controllers { final class ProjectController { public function __construct(mixed \$view, mixed \$creator, callable \$projects, ?callable \$user = null, int \$maxUploadBytes = 524288000) { file_put_contents((string) getenv('WEB_UPLOAD_LIMIT_CAPTURE'), (string) \$maxUploadBytes); } public function index(): \\App\\Core\\Response { return \\App\\Core\\Response::text(''); } public function create(): \\App\\Core\\Response { return \\App\\Core\\Response::text(''); } public function store(\\App\\Core\\Request \$request): \\App\\Core\\Response { return \\App\\Core\\Response::text(''); } } }\n");
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'MEDIA_MAX_UPLOAD_BYTES' => '52428800',
            'MEDIA_PRIVATE_ROOT' => sys_get_temp_dir() . '/cliplab-web-limit-' . bin2hex(random_bytes(6)),
            'WEB_UPLOAD_LIMIT_CAPTURE' => $capture,
        ]);

        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=12M', '-d', 'auto_prepend_file=' . $prepend, $base . '/routes/web.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
                ['bypass_shell' => true]
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertSame(0, $exit, (string) $stderr . (string) $stdout);
            self::assertSame('', $stderr);
            self::assertSame('12457083', file_get_contents($capture));
        } finally {
            @unlink($prepend);
            @unlink($capture);
            $root = $environment['MEDIA_PRIVATE_ROOT'];
            if (is_string($root)) {
                @rmdir($root);
            }
        }
    }
}
