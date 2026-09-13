<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class RenderWorkerCompositionTest extends TestCase
{
    public function testWorkerRegistersRenderHandlerWithSharedRunnerCleanupAndProfilePdo(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/bin/process-jobs.php');

        self::assertIsString($source);
        self::assertStringContainsString("'render_clip' => new RenderClipHandler(", $source);
        // The media renderer keeps its shared runner; the optional YouTube resolver
        // deliberately has a separate, smaller executable allowlist.
        self::assertStringContainsString('new ProcessRunner([$ffprobe, $ffmpeg], $privateRoot)', $source);
        self::assertStringContainsString('$effects = new LeaseProcessingEffectGuard($pdo);', $source);
        self::assertStringContainsString('$cleanups = new RenderArtifactCleanupRepository($pdo);', $source);
        self::assertStringContainsString('$profiles = new ClipRenderProfileRepository($pdo);', $source);
        self::assertStringContainsString('use App\Repositories\ClipRenderProfileRepository;', $source);
        self::assertMatchesRegularExpression(
            '/\$cleanups,\s*\$profiles,\s*\$renderMaxDurationSeconds/s',
            $source
        );
        self::assertStringContainsString('$renderMaxDurationSeconds', $source);
        self::assertStringContainsString('new RenderMaintenance(', $source);
        self::assertStringNotContainsString('$renderer->render(', $source);
        self::assertStringNotContainsString('$provider->', $source);
    }

    public function testRenderBudgetMakesUnsafeLeaseFailBeforeDatabaseOrExternalEffects(): void
    {
        $directory = sys_get_temp_dir() . '/clipforge-render-worker-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700, true));
        $bootstrap = $directory . '/bootstrap.php';
        $effectMarker = $directory . '/external-effect-called';
        $fixture = <<<'PHP'
<?php
namespace App\Core;
final class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if ($key === 'media') {
            return ['queue' => ['lease_seconds' => 300], 'render_timeout_seconds' => 400];
        }
        if ($key === 'gemini') {
            return ['http_timeout_seconds' => 180];
        }
        return $default;
    }
}
final class Database
{
    public static function connection(): never
    {
        file_put_contents((string) getenv('EXTERNAL_EFFECT_MARKER'), 'database');
        throw new \RuntimeException('database must not be reached');
    }
}
PHP;
        self::assertNotFalse(file_put_contents($bootstrap, $fixture));
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'PROCESS_JOBS_BOOTSTRAP_PATH' => $bootstrap,
            'EXTERNAL_EFFECT_MARKER' => $effectMarker,
        ]);

        try {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/bin/process-jobs.php'],
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

            self::assertSame(1, proc_close($process));
            self::assertSame('', $stdout);
            self::assertSame("Falha ao iniciar o worker.\n", $stderr);
            self::assertFileDoesNotExist($effectMarker);
        } finally {
            @unlink($bootstrap);
            @unlink($effectMarker);
            @rmdir($directory);
        }
    }
}
