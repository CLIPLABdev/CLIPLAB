<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Exceptions\MediaValidationException;
use App\Media\ProjectReceipt;
use App\Media\UploadValidator;
use App\Validation\ProjectValidator;
use PHPUnit\Framework\TestCase;

final class EffectiveUploadLimitTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 42];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_FILES = [];
    }

    /** @dataProvider effectiveUploadLimits */
    public function testMediaConfigComputesTheEffectiveWebUploadLimit(
        string $configuredBytes,
        string $uploadMax,
        string $postMax,
        int $expected
    ): void {
        $config = $this->mediaConfigInChildProcess($configuredBytes, $uploadMax, $postMax);

        self::assertSame((int) $configuredBytes, $config['configured_max_upload_bytes']);
        self::assertSame((int) $configuredBytes, $config['max_upload_bytes']);
        self::assertSame($expected, $config['effective_upload_bytes']);
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public function effectiveUploadLimits(): iterable
    {
        yield 'upload limit in lowercase kilobytes' => ['5242880', '512k', '10M', 524288];
        yield 'upload limit in lowercase megabytes' => ['52428800', '2m', '10M', 2097152];
        yield 'post limit minus one percent headroom' => ['52428800', '20M', '12M', 12457083];
        yield 'minimum multipart headroom' => ['52428800', '500M', '1M', 1032192];
        yield 'multipart headroom capped at one megabyte' => ['524288000', '500M', '200M', 208666624];
        yield 'gigabytes are case insensitive' => ['4294967296', '3g', '3G', 3220176896];
        yield 'unsupported suffixes are interpreted as bytes' => ['1099511627776', '2T', '2t', 1];
        yield 'raw byte values are supported' => ['1048576', '700000', '0', 700000];
        yield 'zero means unlimited' => ['5000000', '0', '0', 5000000];
    }

    public function testMediaConfigExposesTheFinitePhpCapacity(): void
    {
        $config = $this->mediaConfigInChildProcess('52428800', '20M', '12M');

        self::assertSame(20971520, $config['php_upload_max_bytes']);
        self::assertSame(12582912, $config['php_post_max_bytes']);
        self::assertSame(12457083, $config['php_upload_capacity_bytes']);
    }

    /** @dataProvider phpSizeUploadErrors */
    public function testUploadValidatorMapsPhpSizeErrorsToThePublicTooLargeCode(int $uploadError): void
    {
        $validator = new UploadValidator(1024, static fn (string $path): string => 'video/mp4');

        try {
            $validator->validate([
                'name' => 'large.mp4',
                'tmp_name' => '',
                'size' => 0,
                'error' => $uploadError,
            ]);
            self::fail('A PHP size error must be reported as an oversized upload.');
        } catch (MediaValidationException $exception) {
            self::assertSame('upload_too_large', $exception->publicCode());
        }
    }

    /** @dataProvider phpSizeUploadErrors */
    public function testProjectValidationReportsPhpSizeErrorsAsAnExceededLimit(int $uploadError): void
    {
        $errors = ProjectValidator::creation([
            'name' => 'Entrevista',
            'source_type' => 'upload',
        ], [
            'video_file' => [
                'name' => 'large.mp4',
                'tmp_name' => '',
                'size' => 0,
                'error' => $uploadError,
            ],
        ]);

        self::assertArrayHasKey('video_file', $errors);
        self::assertStringContainsString('limite', $errors['video_file']);
    }

    /** @return iterable<string, array{int}> */
    public function phpSizeUploadErrors(): iterable
    {
        yield 'php.ini upload limit' => [UPLOAD_ERR_INI_SIZE];
        yield 'MAX_FILE_SIZE form limit' => [UPLOAD_ERR_FORM_SIZE];
    }

    public function testControllerDoesNotInvokeCreationAfterAPhpUploadSizeError(): void
    {
        $creator = new UploadLimitProjectCreator();
        $controller = new ProjectController(new View(), $creator, static fn (): array => []);
        $_FILES['video_file'] = [
            'name' => 'large.mp4',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
            'type' => '',
        ];

        $response = $controller->store(Request::fake('POST', '/projetos', [
            'name' => 'Upload grande',
            'source_type' => 'upload',
            'idempotency_key' => 'large-upload-key',
        ]));

        self::assertSame('/projetos/novo', $response->header('Location'));
        self::assertSame(0, $creator->calls());
        self::assertStringContainsString('limite', $_SESSION['_flash_project_errors']['video_file']);
    }

    public function testCreationViewUsesTheEffectiveLimitInCopyAndMaxFileSize(): void
    {
        $controller = new ProjectController(
            new View(),
            new UploadLimitProjectCreator(),
            static fn (): array => [],
            null,
            12582912
        );

        $html = $controller->create()->body();

        self::assertStringContainsString('name="MAX_FILE_SIZE" value="12582912"', $html);
        self::assertStringContainsString('até 12 MB', $html);
    }

    public function testRouterRejectsAnOversizedRequestBeforeCsrfAndNeverInvokesTheHandler(): void
    {
        $handlerCalled = false;
        $router = new Router(null, 1024);
        $router->post('/projetos', function () use (&$handlerCalled): Response {
            $handlerCalled = true;

            return Response::text('created');
        });

        $response = $router->dispatch(Request::fake('POST', '/projetos', [], ['Content-Length' => '1025']));

        self::assertSame(413, $response->status());
        self::assertStringContainsString('limite', $response->body());
        self::assertFalse($handlerCalled);
    }

    public function testRouterStillRequiresCsrfForARequestWithinThePostLimit(): void
    {
        $router = new Router(null, 1024);
        $router->post('/projetos', fn (): Response => Response::text('created'));

        $response = $router->dispatch(Request::fake('POST', '/projetos', [], ['Content-Length' => '1024']));

        self::assertSame(419, $response->status());
    }

    public function testCapturedRequestExposesTheContentLengthServerHeader(): void
    {
        $server = $_SERVER;
        $get = $_GET;
        $post = $_POST;
        try {
            $_SERVER = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/projetos',
                'REMOTE_ADDR' => '127.0.0.1',
                'CONTENT_LENGTH' => '2048',
            ];
            $_GET = [];
            $_POST = [];

            $request = Request::capture();
        } finally {
            $_SERVER = $server;
            $_GET = $get;
            $_POST = $post;
        }

        self::assertSame('2048', $request->header('Content-Length'));
    }

    /** @return array<string, int|null> */
    private function mediaConfigInChildProcess(string $configuredBytes, string $uploadMax, string $postMax): array
    {
        $base = dirname(__DIR__, 2);
        $autoload = var_export($base . '/vendor/autoload.php', true);
        $mediaConfig = var_export($base . '/config/media.php', true);
        $code = 'require ' . $autoload . '; $config = require ' . $mediaConfig . '; echo json_encode(['
            . '"configured_max_upload_bytes" => $config["configured_max_upload_bytes"] ?? null, '
            . '"max_upload_bytes" => $config["max_upload_bytes"] ?? null, '
            . '"effective_upload_bytes" => $config["effective_upload_bytes"] ?? null, '
            . '"php_upload_max_bytes" => $config["php_upload_max_bytes"] ?? null, '
            . '"php_post_max_bytes" => $config["php_post_max_bytes"] ?? null, '
            . '"php_upload_capacity_bytes" => $config["php_upload_capacity_bytes"] ?? null'
            . '], JSON_THROW_ON_ERROR);';
        $environment = getenv();
        $environment = array_merge(is_array($environment) ? $environment : [], [
            'MEDIA_MAX_UPLOAD_BYTES' => $configuredBytes,
        ]);
        $process = proc_open(
            [PHP_BINARY, '-d', 'upload_max_filesize=' . $uploadMax, '-d', 'post_max_size=' . $postMax, '-r', $code],
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

        self::assertSame(0, $exit, (string) $stderr);
        self::assertSame('', $stderr);

        $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

final class UploadLimitProjectCreator implements ProjectCreator
{
    private int $calls = 0;

    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt
    {
        $this->calls++;

        return new ProjectReceipt(1, 'queued', true);
    }

    public function fromDirectUrl(int $userId, array $input): ProjectReceipt
    {
        $this->calls++;

        return new ProjectReceipt(1, 'queued', true);
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
