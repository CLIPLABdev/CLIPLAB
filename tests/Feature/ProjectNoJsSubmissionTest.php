<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\ProjectCreator;
use App\Controllers\ClipRenderController;
use App\Controllers\ProjectController;
use App\Core\Request;
use App\Core\View;
use App\Media\ClipRenderReceipt;
use App\Media\ProjectReceipt;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Repositories\ClipRenderProfileRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProjectNoJsSubmissionTest extends TestCase
{
    private NoJsSubmissionCreator $creator;
    private ProjectController $controller;

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 27];
        $_FILES = [];
        $this->creator = new NoJsSubmissionCreator();
        $this->controller = new ProjectController(new View(), $this->creator, static fn (): array => []);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_FILES = [];
    }

    public function testNoJavascriptUploadSubmissionUsesTheSelectedFileOnce(): void
    {
        $_FILES['video_file'] = [
            'name' => 'entrevista.mp4',
            'tmp_name' => '/tmp/entrevista.mp4',
            'error' => UPLOAD_ERR_OK,
            'size' => 8192,
            'type' => 'video/mp4',
        ];

        $response = $this->controller->store(Request::fake('POST', '/projetos', [
            'name' => 'Entrevista',
            'source_type' => 'upload',
            'idempotency_key' => 'no-js-upload',
        ]));

        self::assertSame('/projetos?created=1', $response->header('Location'));
        self::assertSame('upload', $this->creator->method());
        self::assertSame('entrevista.mp4', $this->creator->file()['name']);
    }

    public function testNoJavascriptUrlSubmissionDoesNotReadAnUploadField(): void
    {
        $_FILES['video_file'] = [
            'name' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
            'type' => '',
        ];

        $response = $this->controller->store(Request::fake('POST', '/projetos', [
            'name' => 'Origem remota',
            'source_type' => 'direct_url',
            'source_url' => 'https://cdn.example.test/entrevista.mp4',
            'idempotency_key' => 'no-js-url',
        ]));

        self::assertSame('/projetos?created=1', $response->header('Location'));
        self::assertSame('direct_url', $this->creator->method());
        self::assertSame('https://cdn.example.test/entrevista.mp4', $this->creator->input()['source_url']);
    }

    public function testNoJavascriptMarkupHasOnlyOneFileAndUrlControl(): void
    {
        $html = $this->controller->create()->body();

        self::assertSame(1, substr_count($html, 'name="video_file"'));
        self::assertSame(1, substr_count($html, 'name="source_url"'));
        self::assertStringNotContainsString('<noscript>\n            <fieldset', $html);
    }

    public function testNoJavascriptManualSubmissionPersistsExactlyOneCanonicalKeyframe(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(
            'CREATE TABLE clip_render_profiles ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, clip_id INTEGER NOT NULL, render_revision INTEGER NOT NULL, '
            . 'aspect_ratio TEXT NOT NULL, reframe_mode TEXT NOT NULL, output_width INTEGER NULL, '
            . 'output_height INTEGER NULL, detector_version TEXT NULL)'
        );
        $pdo->exec(
            'CREATE TABLE clip_reframe_keyframes ('
            . 'render_profile_id INTEGER NOT NULL, sequence_index INTEGER NOT NULL, at_ms INTEGER NOT NULL, '
            . 'center_x TEXT NOT NULL, center_y TEXT NOT NULL, source TEXT NOT NULL)'
        );
        $profiles = new ClipRenderProfileRepository($pdo);
        $validator = new ReframePlanValidator();
        $controller = new ClipRenderController(
            static function (
                int $clipId,
                int $userId,
                string $start,
                string $end,
                ReframeSubmission $submission
            ) use ($profiles, $validator): ClipRenderReceipt {
                self::assertSame(27, $userId);
                self::assertSame('1.250', $start);
                self::assertSame('4.750', $end);
                $profiles->create($clipId, 1, $validator->validate($submission, 3500));

                return new ClipRenderReceipt($clipId, 31, 1, true);
            }
        );

        $response = $controller->store(Request::fake('POST', '/clips/71/render', [
            'start_time' => '1.250',
            'end_time' => '4.750',
            'aspect_ratio' => '9:16',
            'reframe_mode' => 'manual',
            'focus_x' => '0.250000',
            'focus_y' => '0.750000',
            'reframe_keyframes' => '',
        ]), ['id' => '71']);

        self::assertSame('/projetos/31', $response->header('Location'));
        $rows = $pdo->query(
            'SELECT sequence_index, at_ms, center_x, center_y, source FROM clip_reframe_keyframes'
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([[
            'sequence_index' => 0,
            'at_ms' => 0,
            'center_x' => '0.250000',
            'center_y' => '0.750000',
            'source' => 'manual',
        ]], array_map(static fn (array $row): array => [
            'sequence_index' => (int) $row['sequence_index'],
            'at_ms' => (int) $row['at_ms'],
            'center_x' => (string) $row['center_x'],
            'center_y' => (string) $row['center_y'],
            'source' => (string) $row['source'],
        ], $rows));
    }

    public function testLegacyAutomaticFailureCanBeResubmittedWithoutJavascriptAsCenter(): void
    {
        $html = (new View())->render('projects.show', [
            'title' => 'Projetos',
            'user' => ['id' => 27, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 7, 'plan_name' => 'Free', 'monthly_minutes' => 60],
            'project' => [
                'id' => 31,
                'name' => 'Projeto reframe',
                'status' => 'suggestions_ready',
                'progress' => 100,
                'stage' => 'Sugestões prontas',
                'message' => 'Os cortes estão disponíveis.',
                'analysis_status' => 'completed',
                'video_summary' => null,
                'updated_at' => '2026-09-04 12:00:00',
            ],
            'clips' => [[
                'id' => 71,
                'title' => 'Corte 71',
                'start_time' => 1.25,
                'end_time' => 4.75,
                'duration_seconds' => 3.5,
                'viral_score' => 91,
                'hook' => 'Gancho',
                'reason' => 'Motivo',
                'category' => 'insight',
                'status' => 'suggested',
                'render_start_time' => null,
                'render_end_time' => null,
                'output_aspect_ratio' => 'original',
                'reframe_mode' => 'original',
                'source_preview_url' => '/clips/71/source-preview',
            ]],
            'clipRenderOld' => [
                'clip_id' => 71,
                'start_time' => '1.250',
                'end_time' => '4.750',
                'aspect_ratio' => '9:16',
                'reframe_mode' => 'auto',
                'focus_x' => '',
                'focus_y' => '',
                'reframe_keyframes' => '[{"at_ms":0,"center_x":0.25,"center_y":0.5},{"at_ms":3500,"center_x":0.75,"center_y":0.5}]',
            ],
            'mediaPipeConsentActive' => false,
            'reframeUiConfig' => ['max_duration_ms' => 90000, 'max_frames' => 180, 'max_edge' => 320],
        ])->body();

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML($html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $form = $xpath->query('//form[@action="/clips/71/render"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $inputValue = static function (string $name) use ($xpath, $form): string {
            $node = $xpath->query('.//input[@name="' . $name . '"]', $form)->item(0);
            self::assertInstanceOf(\DOMElement::class, $node);

            return $node->getAttribute('value');
        };
        $selectedValue = static function (string $name) use ($xpath, $form): string {
            $node = $xpath->query('.//select[@name="' . $name . '"]/option[@selected]', $form)->item(0);
            self::assertInstanceOf(\DOMElement::class, $node);

            return $node->getAttribute('value');
        };
        $payload = [
            'start_time' => $inputValue('start_time'),
            'end_time' => $inputValue('end_time'),
            'aspect_ratio' => $selectedValue('aspect_ratio'),
            'reframe_mode' => $selectedValue('reframe_mode'),
            'focus_x' => $inputValue('focus_x'),
            'focus_y' => $inputValue('focus_y'),
            'reframe_keyframes' => $inputValue('reframe_keyframes'),
        ];

        self::assertSame('9:16', $payload['aspect_ratio']);
        self::assertSame('center', $payload['reframe_mode']);
        self::assertSame('', $payload['focus_x']);
        self::assertSame('', $payload['focus_y']);
        self::assertSame('', $payload['reframe_keyframes']);

        $validator = new ReframePlanValidator();
        $controller = new ClipRenderController(
            static function (
                int $clipId,
                int $userId,
                string $start,
                string $end,
                ReframeSubmission $submission
            ) use ($validator): ClipRenderReceipt {
                $plan = $validator->validate($submission, 3500);
                self::assertSame('center', $plan->mode());
                self::assertSame('9:16', $plan->aspectRatio()->value());
                self::assertSame([], $plan->keyframes());

                return new ClipRenderReceipt($clipId, 31, 1, true);
            }
        );
        $response = $controller->store(Request::fake('POST', '/clips/71/render', $payload), ['id' => '71']);
        self::assertSame('/projetos/31', $response->header('Location'));
    }
}

final class NoJsSubmissionCreator implements ProjectCreator
{
    private string $method = '';
    /** @var array<string, mixed> */
    private array $input = [];
    /** @var array<string, mixed> */
    private array $file = [];

    public function fromUpload(int $userId, array $input, array $file): ProjectReceipt
    {
        $this->method = 'upload';
        $this->input = $input;
        $this->file = $file;

        return new ProjectReceipt(28, 'queued', true);
    }

    public function fromDirectUrl(int $userId, array $input): ProjectReceipt
    {
        $this->method = 'direct_url';
        $this->input = $input;
        $this->file = [];

        return new ProjectReceipt(28, 'queued', true);
    }

    public function method(): string { return $this->method; }
    /** @return array<string, mixed> */
    public function input(): array { return $this->input; }
    /** @return array<string, mixed> */
    public function file(): array { return $this->file; }
}
