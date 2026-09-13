<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ProjectCreator;
use App\Controllers\ProjectController;
use App\Core\Request;
use App\Core\View;
use App\Media\ProjectReceipt;
use App\Media\UploadValidator;
use PHPUnit\Framework\TestCase;

final class ProjectControllerTest extends TestCase
{
    private FakeProjectCreator $creator;
    private ProjectController $controller;

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 42];
        $_FILES = [];
        $this->creator = new FakeProjectCreator();
        $this->controller = new ProjectController(new View(), $this->creator, static fn (int $userId, int $limit): array => []);
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        $_SESSION = [];
    }

    public function testStorePassesAuthenticatedOwnerAndRedirectsToLibrary(): void
    {
        $response = $this->controller->store(Request::fake('POST', '/projetos', [
            'name' => 'Cortes do episódio',
            'source_type' => 'direct_url',
            'source_url' => 'https://cdn.example.test/episode.mp4',
            'idempotency_key' => 'browser-key',
            'user_id' => '999',
            'status' => 'ready',
        ]));

        self::assertSame(42, $this->creator->lastUserId());
        self::assertSame('direct_url', $this->creator->lastMethod());
        self::assertSame('Cortes do episódio', $this->creator->lastInput()['name']);
        self::assertArrayNotHasKey('user_id', $this->creator->lastInput());
        self::assertSame('/projetos?created=1', $response->header('Location'));
    }

    public function testPersistsExplicitAutomaticExportPreference(): void
    {
        $this->controller->store(Request::fake('POST','/projetos',[
            'name'=>'Automático','source_type'=>'direct_url','source_url'=>'https://cdn.example.test/video.mp4',
            'idempotency_key'=>'auto-request','auto_render_requested'=>'1',
        ]));
        self::assertSame('1',$this->creator->lastInput()['auto_render_requested'] ?? null);
    }

    public function testInvalidAutomaticExportPreferenceNeverReachesCreator(): void
    {
        $response=$this->controller->store(Request::fake('POST','/projetos',[
            'name'=>'Inválido','source_type'=>'direct_url','source_url'=>'https://cdn.example.test/video.mp4',
            'idempotency_key'=>'auto-invalid','auto_render_requested'=>['1'],
        ]));
        self::assertSame('/projetos/novo',$response->header('Location'));
        self::assertArrayHasKey('form',$_SESSION['_flash_project_errors']);
    }

    public function testCreateShowsTheSmallerPerPlanUploadLimitAndAutomaticChoice(): void
    {
        $controller=new ProjectController(new View(),$this->creator,static fn(): array=>[],null,524288000,static fn(int $user): int=>104857600);
        $response=$controller->create();
        self::assertStringContainsString('100 MB',$response->body());
        self::assertStringContainsString('name="auto_render_requested"',$response->body());
    }

    public function testStoreReadsFilesOnlyForUploadMode(): void
    {
        $_FILES['video_file'] = [
            'name' => 'origem.mp4',
            'tmp_name' => '/tmp/origem.mp4',
            'error' => UPLOAD_ERR_OK,
            'size' => 42,
            'type' => 'video/mp4',
        ];

        $response = $this->controller->store(Request::fake('POST', '/projetos', [
            'name' => 'Upload seguro',
            'source_type' => 'upload',
            'idempotency_key' => 'upload-key',
        ]));

        self::assertSame('upload', $this->creator->lastMethod());
        self::assertSame('origem.mp4', $this->creator->lastFile()['name']);
        self::assertSame('/projetos?created=1', $response->header('Location'));
    }

    public function testStorePreservesSafeFieldsButDoesNotFlashSensitiveUrl(): void
    {
        $response = $this->controller->store(Request::fake('POST', '/projetos', [
            'name' => 'Tentativa',
            'source_type' => 'direct_url',
            'source_url' => 'https://user:secret@example.com/video.mp4?token=secret',
            'idempotency_key' => 'key',
        ]));

        self::assertSame('/projetos/novo', $response->header('Location'));
        self::assertSame('Tentativa', $_SESSION['_flash_project_old']['name']);
        self::assertSame('direct_url', $_SESSION['_flash_project_old']['source_type']);
        self::assertArrayNotHasKey('source_url', $_SESSION['_flash_project_old']);
        self::assertArrayHasKey('source_url', $_SESSION['_flash_project_errors']);
    }

    public function testIndexScopesTheLibraryToTheAuthenticatedUserAndCapsThePage(): void
    {
        $seen = [];
        $controller = new ProjectController(
            new View(),
            $this->creator,
            static function (int $userId, int $limit) use (&$seen): array {
                $seen = [$userId, $limit];

                return [];
            }
        );

        $response = $controller->index();

        self::assertSame(200, $response->status());
        self::assertSame([42, 24], $seen);
    }

    public function testEmptyUploadReturnsToTheFormWithInstructionsToChooseAnotherFile(): void
    {
        $emptyFile = tmpfile();
        self::assertIsResource($emptyFile);
        try {
            $_FILES['video_file'] = [
                'name' => 'vazio.mp4',
                'tmp_name' => stream_get_meta_data($emptyFile)['uri'],
                'error' => UPLOAD_ERR_OK,
                'size' => 0,
                'type' => 'video/mp4',
            ];
            $creator = new class implements ProjectCreator {
                public function fromUpload(int $userId, array $input, array $file): ProjectReceipt
                {
                    (new UploadValidator(1024))->validate($file);
                    return new ProjectReceipt(7, 'queued', true);
                }

                public function fromDirectUrl(int $userId, array $input): ProjectReceipt
                {
                    throw new \LogicException('This submission must validate its uploaded file.');
                }
            };
            $controller = new ProjectController(new View(), $creator, static fn (): array => []);
            $response = $controller->store(Request::fake('POST', '/projetos', [
                'name' => 'Vídeo vazio',
                'source_type' => 'upload',
                'idempotency_key' => 'empty-upload',
            ]));

            self::assertSame('/projetos/novo', $response->header('Location'));
            self::assertArrayNotHasKey('_flash_project_created', $_SESSION);
            self::assertSame('Vídeo vazio', $_SESSION['_flash_project_old']['name']);
            $form = $controller->create()->body();
            self::assertStringContainsString(
                'O arquivo enviado está vazio ou é inválido. Selecione um arquivo de vídeo válido e envie novamente.',
                $form
            );
            self::assertStringNotContainsString('Não foi possível criar o projeto agora.', $form);
        } finally {
            fclose($emptyFile);
        }
    }
}

final class FakeProjectCreator implements ProjectCreator
{
    private int $userId = 0;
    private string $method = '';
    /** @var array<string, mixed> */
    private array $input = [];
    /** @var array<string, mixed> */
    private array $file = [];

    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt
    {
        $this->userId = $userId;
        $this->method = 'upload';
        $this->input = $input;
        $this->file = $file;

        return new ProjectReceipt(7, 'queued', true);
    }

    public function fromDirectUrl(int $userId, array $input): ProjectReceipt
    {
        $this->userId = $userId;
        $this->method = 'direct_url';
        $this->input = $input;
        $this->file = [];

        return new ProjectReceipt(7, 'queued', true);
    }

    public function lastUserId(): int { return $this->userId; }
    public function lastMethod(): string { return $this->method; }
    /** @return array<string, mixed> */
    public function lastInput(): array { return $this->input; }
    /** @return array<string, mixed> */
    public function lastFile(): array { return $this->file; }
}
