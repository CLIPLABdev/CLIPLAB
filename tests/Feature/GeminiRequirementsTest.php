<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class GeminiRequirementsTest extends TestCase
{
    public function testMissingCredentialsAreWarningsAndCheckerNeverPrintsValues(): void
    {
        $result = $this->runChecker([
            'GEMINI_API_KEY' => ' ',
            'GEMINI_MODEL' => ' ',
            'GEMINI_HTTP_TIMEOUT_SECONDS' => '180',
            'QUEUE_LEASE_SECONDS' => '300',
        ]);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('[WARN] Chave Gemini', $result['stdout']);
        self::assertStringContainsString('[WARN] Modelo Gemini', $result['stdout']);
        self::assertStringContainsString('[OK] Armazenamento privado legível', $result['stdout']);
        self::assertStringContainsString('[OK] Armazenamento privado gravável', $result['stdout']);
    }

    public function testConfiguredCredentialsAreReportedWithoutPrintingTheSecretOrModel(): void
    {
        $secret = 'checker-key-must-never-appear';
        $model = 'checker-model-must-never-appear';
        $result = $this->runChecker([
            'GEMINI_API_KEY' => $secret,
            'GEMINI_MODEL' => $model,
            'GEMINI_HTTP_TIMEOUT_SECONDS' => '180',
            'QUEUE_LEASE_SECONDS' => '300',
        ]);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('[OK] Chave Gemini', $result['stdout']);
        self::assertStringContainsString('[OK] Modelo Gemini', $result['stdout']);
        self::assertStringNotContainsString($secret, $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString($model, $result['stdout'] . $result['stderr']);
    }

    public function testUnsafeLeaseBudgetIsAConfigurationFailureAndCronBudgetIsExplicit(): void
    {
        $result = $this->runChecker([
            'GEMINI_API_KEY' => ' ',
            'GEMINI_MODEL' => ' ',
            'GEMINI_HTTP_TIMEOUT_SECONDS' => '60',
            'QUEUE_LEASE_SECONDS' => '60',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FALHA] Lease do worker para Gemini', $result['stdout']);
        self::assertStringContainsString('[WARN] Orçamento operacional do cron', $result['stdout']);
    }

    public function testLeaseCoversSequentialAudioExtractionAndCaptionProvider(): void
    {
        $result = $this->runChecker([
            'GEMINI_HTTP_TIMEOUT_SECONDS' => '300',
            'PROCESS_TIMEOUT_SECONDS' => '60',
            'RENDER_TIMEOUT_SECONDS' => '240',
            'QUEUE_LEASE_SECONDS' => '330',
        ]);
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FALHA] Lease do worker', $result['stdout']);
    }

    public function testYoutubeLeaseIncludesResolutionDownloadMergeAndProbe(): void
    {
        $settings = [
            'YOUTUBE_IMPORT_ENABLED' => 'true',
            'YTDLP_TIMEOUT_SECONDS' => '90',
            'MEDIA_DOWNLOAD_TIMEOUT_SECONDS' => '120',
            'PROCESS_TIMEOUT_SECONDS' => '60',
            'GEMINI_HTTP_TIMEOUT_SECONDS' => '180',
            'RENDER_TIMEOUT_SECONDS' => '240',
            'QUEUE_LEASE_SECONDS' => '300',
        ];
        self::assertSame(1, $this->runChecker($settings)['exit']);
        $settings['QUEUE_LEASE_SECONDS'] = '600';
        self::assertSame(0, $this->runChecker($settings)['exit']);
    }

    /** @param array<string, string> $overrides @return array{exit:int,stdout:string,stderr:string} */
    private function runChecker(array $overrides): array
    {
        $root = sys_get_temp_dir() . '/cliplab-gemini-check-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'YOUTUBE_IMPORT_ENABLED' => 'false',
            'MEDIA_DOWNLOAD_TIMEOUT_SECONDS' => '120',
            'PROCESS_TIMEOUT_SECONDS' => '60',
            'RENDER_TIMEOUT_SECONDS' => '240',
        ], $overrides, [
            'MEDIA_PRIVATE_ROOT' => $root,
            'FFPROBE_BINARY' => $root . '/missing-ffprobe',
        ]);
        try {
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-requirements.php'],
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

            return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
        } finally {
            @rmdir($root);
        }
    }
}
