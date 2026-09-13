<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ClipRenderController;
use App\Controllers\ProjectSuggestionController;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\ClipRenderValidationException;
use App\Media\ClipRenderReceipt;
use App\Media\Reframe\ReframeSubmission;
use App\Services\ClipStatusService;
use PHPUnit\Framework\TestCase;

final class ClipRenderAccessTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testOwnerRenderRequestRedirectsToTheOwningProject(): void
    {
        $submitted = null;
        $controller = new ClipRenderController(
            static function (
                int $clipId,
                int $userId,
                string $start,
                string $end,
                ReframeSubmission $reframe
            ) use (&$submitted): ClipRenderReceipt {
                $submitted = $reframe;

                return new ClipRenderReceipt($clipId, 31, 1, true);
            }
        );

        $response = $controller->store(
            Request::fake('POST', '/clips/71/render', [
                'start_time' => '12.500',
                'end_time' => '35.250',
            ]),
            ['id' => '71']
        );

        self::assertSame(302, $response->status());
        self::assertSame('/projetos/31', $response->header('Location'));
        self::assertSame('Renderização solicitada.', Session::pull('clip_render_feedback'));
        self::assertInstanceOf(ReframeSubmission::class, $submitted);
        self::assertSame('original', $submitted->aspectRatio());
        self::assertSame('original', $submitted->mode());
        self::assertFalse($submitted->hasServerOwnedFields());
    }

    public function testValidationFailureReturnsToTheAuthorizedProjectWithFeedbackAndOldInput(): void
    {
        $controller = new ClipRenderController(
            static function (): never {
                throw new ClipRenderValidationException(
                    ['end_time' => 'O corte deve ter pelo menos 1 segundo.'],
                    31
                );
            }
        );

        $response = $controller->store(
            Request::fake('POST', '/clips/71/render', [
                'start_time' => '12.500',
                'end_time' => '12.750',
            ]),
            ['id' => '71']
        );

        self::assertSame(302, $response->status());
        self::assertSame('/projetos/31', $response->header('Location'));
        self::assertSame('Revise o intervalo informado.', Session::pull('clip_render_feedback'));
        self::assertSame(
            ['end_time' => 'O corte deve ter pelo menos 1 segundo.'],
            Session::pull('clip_render_errors')
        );
        self::assertSame(
            [
                'clip_id' => 71,
                'start_time' => '12.500',
                'end_time' => '12.750',
                'aspect_ratio' => 'original',
                'reframe_mode' => 'original',
                'focus_x' => '',
                'focus_y' => '',
                'reframe_keyframes' => '',
            ],
            Session::pull('clip_render_old')
        );
    }

    public function testPresentServerOwnedFieldIsDetectedAndNeverFlashedWithOversizedJson(): void
    {
        $submitted = null;
        $controller = new ClipRenderController(
            static function (
                int $clipId,
                int $userId,
                string $start,
                string $end,
                ReframeSubmission $reframe
            ) use (&$submitted): never {
                $submitted = $reframe;
                throw new ClipRenderValidationException(
                    ['reframe' => 'Configuração de enquadramento inválida.'],
                    31
                );
            }
        );
        $privateJson = str_repeat('x', 16385);

        $response = $controller->store(
            Request::fake('POST', '/clips/71/render', [
                'start_time' => '12.500',
                'end_time' => '14.500',
                'aspect_ratio' => '9:16',
                'reframe_mode' => 'manual',
                'focus_x' => '0.250000',
                'focus_y' => '0.500000',
                'reframe_keyframes' => $privateJson,
                'detector_version' => null,
                'output_width' => 'private-width',
                'output_height' => 'private-height',
                'filtergraph' => 'private-filter',
                'ffmpeg_args' => 'private-args',
            ]),
            ['id' => '71']
        );

        self::assertSame('/projetos/31', $response->header('Location'));
        self::assertInstanceOf(ReframeSubmission::class, $submitted);
        self::assertSame('9:16', $submitted->aspectRatio());
        self::assertSame('manual', $submitted->mode());
        self::assertSame($privateJson, $submitted->keyframesJson());
        self::assertTrue($submitted->hasServerOwnedFields());
        self::assertSame([
            'clip_id' => 71,
            'start_time' => '12.500',
            'end_time' => '14.500',
            'aspect_ratio' => '9:16',
            'reframe_mode' => 'manual',
            'focus_x' => '0.250000',
            'focus_y' => '0.500000',
        ], Session::pull('clip_render_old'));
    }

    public function testRequestPresenceDistinguishesNullInputFromAbsence(): void
    {
        $request = Request::fake('POST', '/clips/71/render', ['detector_version' => null]);

        self::assertTrue($request->hasInput('detector_version'));
        self::assertFalse($request->hasInput('filtergraph'));
    }

    public function testGuestAndInvalidOrUnavailableClipNeverReachTheRenderService(): void
    {
        $calls = 0;
        $controller = new ClipRenderController(static function () use (&$calls): ?ClipRenderReceipt {
            ++$calls;
            return null;
        });
        $_SESSION = [];
        $guest = $controller->store(Request::fake('POST', '/clips/71/render'), ['id' => '71']);
        self::assertSame('/login', $guest->header('Location'));
        self::assertSame(0, $calls);
        $_SESSION = ['user_id' => 7];
        $foreign = $controller->store(Request::fake('POST', '/clips/71/render'), ['id' => '71']);
        $missing = $controller->store(Request::fake('POST', '/clips/999999999/render'), ['id' => '999999999']);
        foreach (['01', '+1', '1e2', '18446744073709551615', '7%2Fdownload'] as $invalidId) {
            $invalid = $controller->store(Request::fake('POST', '/clips/' . $invalidId . '/render'), ['id' => $invalidId]);
            self::assertSame(404, $invalid->status());
            self::assertSame($foreign->body(), $invalid->body());
        }
        self::assertSame(404, $foreign->status());
        self::assertSame($missing->body(), $foreign->body());
    }

    public function testPostRouteRejectsAnInvalidCsrfTokenBeforeDispatch(): void
    {
        $controller = new ClipRenderController(static fn (): ClipRenderReceipt => new ClipRenderReceipt(71, 31, 1, true));
        $router = new Router();
        $router->post('/clips/{id}/render', static fn (Request $request, array $parameters): Response => $controller->store($request, $parameters));
        Csrf::token();
        $response = $router->dispatch(Request::fake('POST', '/clips/71/render', ['_token' => 'invalid']));
        self::assertSame(419, $response->status());
    }

    public function testClipStatusContainsExactlyThePublicContract(): void
    {
        $service = new ClipStatusService(static fn (int $clipId, int $userId): array => [
            'id' => $clipId, 'project_id' => 31, 'status' => 'completed',
            'render_start_time' => 12.5, 'render_end_time' => 35.25,
            'render_error_code' => 'private-error-code', 'updated_at' => '2026-09-04 12:00:00',
            'output_aspect_ratio' => '9:16', 'reframe_mode' => 'manual',
            'output_width' => 720, 'output_height' => 1280,
            'detector_version' => 'private-detector', 'keyframes' => [['private' => true]],
            'profile_id' => 999,
            'object_key' => 'processed/31/private.mp4', 'absolute_path' => 'C:\\private\\private.mp4',
        ]);
        $status = $service->forOwnedClip(71, 7);
        self::assertNotNull($status);
        self::assertSame([
            'id',
            'status',
            'stage',
            'message',
            'render_start_time',
            'render_end_time',
            'output_aspect_ratio',
            'reframe_mode',
            'thumbnail_url',
            'download_url',
            'updated_at',
        ], array_keys($status));
        self::assertSame('9:16', $status['output_aspect_ratio']);
        self::assertSame('manual', $status['reframe_mode']);
        self::assertSame('/clips/71/thumbnail', $status['thumbnail_url']);
        self::assertSame('/clips/71/download', $status['download_url']);
        foreach ([
            'output_width',
            'output_height',
            'detector_version',
            'keyframes',
            'profile_id',
            'object_key',
            'absolute_path',
        ] as $privateField) {
            self::assertArrayNotHasKey($privateField, $status);
        }
        self::assertStringNotContainsString('private', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function testStatusReturnsTheSamePrivateJsonNotFoundForEveryInvalidLookup(): void
    {
        $statuses = new ClipStatusService(static fn (): ?array => null);
        $controller = new ClipRenderController(static fn (): ?ClipRenderReceipt => null, $statuses);
        $foreign = $controller->status(Request::fake('GET', '/api/clips/71/status'), ['id' => '71']);
        $missing = $controller->status(Request::fake('GET', '/api/clips/999999999/status'), ['id' => '999999999']);
        $malformed = $controller->status(Request::fake('GET', '/api/clips/01/status'), ['id' => '01']);
        self::assertSame(404, $foreign->status());
        self::assertSame('{"error":"clip_not_found"}', $foreign->body());
        self::assertSame($foreign->body(), $missing->body());
        self::assertSame($foreign->body(), $malformed->body());
        self::assertSame('no-store', $foreign->header('Cache-Control'));
    }

    public function testProjectDetailShowsSuggestionsOnlyForTheLatestCompletedAnalysis(): void
    {
        $analysisStatus = 'analyzing';
        $projectStatus = 'analyzing';
        $clipLoads = 0;
        $controller = new ProjectSuggestionController(
            new View(),
            static function (int $projectId) use (&$analysisStatus, &$projectStatus): array {
                return [
                    'id' => $projectId, 'name' => 'Projeto', 'status' => $projectStatus,
                    'progress' => 90, 'analysis_status' => $analysisStatus,
                    'video_summary' => null, 'updated_at' => '2026-09-04 12:00:00',
                ];
            },
            static function () use (&$clipLoads): array {
                ++$clipLoads;
                return [[
                    'id' => 71, 'title' => 'Sugestão atual', 'start_time' => 10, 'end_time' => 40,
                    'duration_seconds' => 30, 'viral_score' => 90, 'hook' => 'Hook',
                    'reason' => 'Reason', 'category' => 'insight', 'status' => 'suggested',
                ]];
            }
        );

        $pending = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31']);
        self::assertSame(0, $clipLoads);
        self::assertStringNotContainsString('Sugestão atual', $pending->body());

        $analysisStatus = 'completed';
        $projectStatus = 'rendering';
        $rendering = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31']);
        self::assertSame(1, $clipLoads);
        self::assertStringContainsString('Sugestão atual', $rendering->body());

        $projectStatus = 'completed';
        $completed = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31']);
        self::assertSame(2, $clipLoads);
        self::assertStringContainsString('Sugestão atual', $completed->body());
    }

    public function testProjectDetailExposesOnlyNormalizedReframeErrorsAndOldInputToTheView(): void
    {
        $viewRoot = sys_get_temp_dir() . '/clip-render-old-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($viewRoot . '/projects', 0700, true));
        self::assertNotFalse(file_put_contents(
            $viewRoot . '/projects/show.php',
            '<?php echo json_encode(["errors" => $clipRenderErrors, "old" => $clipRenderOld], JSON_THROW_ON_ERROR);'
        ));
        Session::flash('clip_render_errors', [
            'reframe' => 'Configuração de enquadramento inválida.',
            'detector_version' => 'private-detector-error',
        ]);
        Session::flash('clip_render_old', [
            'clip_id' => 71,
            'start_time' => 'private-start',
            'end_time' => '12.750',
            'aspect_ratio' => 'private-aspect',
            'reframe_mode' => 'private-mode',
            'focus_x' => 'private-focus',
            'focus_y' => '0.500000',
            'reframe_keyframes' => '[]',
            'detector_version' => 'private-detector',
            'output_width' => 'private-width',
            'filtergraph' => 'private-filter',
        ]);
        $controller = new ProjectSuggestionController(
            new View($viewRoot),
            static fn (int $projectId): array => [
                'id' => $projectId,
                'name' => 'Projeto',
                'status' => 'suggestions_ready',
                'progress' => 92,
                'analysis_status' => 'completed',
                'video_summary' => null,
                'updated_at' => '2026-09-04 12:00:00',
            ],
            static fn (): array => []
        );

        try {
            $response = $controller->show(Request::fake('GET', '/projetos/31'), ['id' => '31']);
        } finally {
            @unlink($viewRoot . '/projects/show.php');
            @rmdir($viewRoot . '/projects');
            @rmdir($viewRoot);
        }

        $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['reframe' => 'Configuração de enquadramento inválida.'],
            $data['errors']
        );
        self::assertSame([
            'clip_id' => 71,
            'start_time' => '',
            'end_time' => '12.750',
            'aspect_ratio' => 'original',
            'reframe_mode' => 'original',
            'focus_x' => '',
            'focus_y' => '0.500000',
            'reframe_keyframes' => '[]',
        ], $data['old']);
        self::assertStringNotContainsString('private-', $response->body());
    }
}
