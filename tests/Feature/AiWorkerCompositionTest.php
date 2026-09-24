<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class AiWorkerCompositionTest extends TestCase
{
    public function testWorkerSourceComposesExactlyTheFourSupportedHandlers(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/process-jobs.php');

        foreach ([
            "'probe_source' => new ProbeSourceHandler",
            "'fetch_and_probe' => new FetchAndProbeHandler",
            "'analyze_video' => new AnalyzeVideoHandler",
            "'generate_clips' => new GenerateClipsHandler",
            'new AiPipelineStarter(',
            'new CreditReservationService(',
            'new DatabaseJobDispatcher(',
            "Config::get('gemini'",
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringContainsString('WorkerLeaseBudget::requiredSeconds(', $source);
        self::assertStringNotContainsString('max($httpTimeoutSeconds, $renderTimeoutSeconds) + 30', $source);
        self::assertStringNotContainsString('$downloadTimeoutSeconds + $processTimeoutSeconds', $source);
        self::assertStringContainsString('new RenderMaintenance(', $source);
        self::assertStringContainsString("'unconfigured'", $source);
    }

    public function testMissingGeminiCredentialsBootWithoutCallingTheTransport(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $eligible = (int) $pdo->query("SELECT COUNT(*) FROM processing_jobs WHERE queue_name = 'media' AND status IN ('queued', 'retry')")->fetchColumn();
        if ($eligible !== 0) {
            self::markTestSkipped('The shared test queue is not empty.');
        }

        $root = sys_get_temp_dir() . '/cliplab-ai-worker-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $prepend = tempnam(sys_get_temp_dir(), 'ai-worker-prepend-');
        $networkMarker = tempnam(sys_get_temp_dir(), 'ai-worker-network-');
        self::assertNotFalse($prepend);
        self::assertNotFalse($networkMarker);
        unlink($networkMarker);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($prepend, "<?php\nnamespace { require {$autoload}; }\nnamespace App\\Gemini { final class CurlGeminiTransport implements GeminiTransport { public function request(string \$method, string \$url, array \$headers, ?string \$body, int \$timeoutSeconds, int \$responseLimitBytes): GeminiHttpResponse { file_put_contents((string) getenv('AI_NETWORK_MARKER'), 'request'); throw new \\RuntimeException('network-called'); } public function upload(string \$url, array \$headers, string \$absolutePath, int \$sizeBytes, int \$timeoutSeconds, int \$responseLimitBytes): GeminiHttpResponse { file_put_contents((string) getenv('AI_NETWORK_MARKER'), 'upload'); throw new \\RuntimeException('network-called'); } } }\n");

        try {
            $result = $this->runWorker([
                'DB_DSN' => $dsn,
                'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
                'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
                'MEDIA_PRIVATE_ROOT' => $root,
                'GEMINI_API_KEY' => ' ',
                'GEMINI_MODEL' => ' ',
                'GEMINI_HTTP_TIMEOUT_SECONDS' => '180',
                'QUEUE_LEASE_SECONDS' => '300',
                'AI_NETWORK_MARKER' => $networkMarker,
            ], $prepend);

            self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            self::assertSame('', $result['stderr']);
            self::assertJson($result['stdout']);
            self::assertFileDoesNotExist($networkMarker);
        } finally {
            @unlink($prepend);
            @unlink($networkMarker);
            @rmdir($root);
        }
    }

    public function testLeaseShorterThanProviderBudgetFailsBeforeWorkerStarts(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $root = sys_get_temp_dir() . '/cliplab-ai-lease-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            $result = $this->runWorker([
                'DB_DSN' => $dsn,
                'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
                'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
                'MEDIA_PRIVATE_ROOT' => $root,
                'GEMINI_API_KEY' => ' ',
                'GEMINI_MODEL' => ' ',
                'GEMINI_HTTP_TIMEOUT_SECONDS' => '60',
                'QUEUE_LEASE_SECONDS' => '60',
            ]);

            self::assertSame(1, $result['exit']);
            self::assertSame('', $result['stdout']);
            self::assertSame("Falha ao iniciar o worker.\n", $result['stderr']);
        } finally {
            @rmdir($root);
        }
    }

    /** @param array<string, string> $overrides @return array{exit:int,stdout:string,stderr:string} */
    private function runWorker(array $overrides, ?string $prepend = null): array
    {
        $command = [PHP_BINARY];
        if ($prepend !== null) {
            $command[] = '-d';
            $command[] = 'auto_prepend_file=' . $prepend;
        }
        $command[] = dirname(__DIR__, 2) . '/bin/process-jobs.php';
        $command[] = '--limit=1';
        $command[] = '--time-budget=5';
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'APP_ENV_FILE' => sys_get_temp_dir() . '/cliplab-no-env-file',
        ], $overrides);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }
}
