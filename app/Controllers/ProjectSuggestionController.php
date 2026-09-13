<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\ProjectStatusService;

final class ProjectSuggestionController
{
    private const DECIMAL_PATTERN = '/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?\z/D';
    private const COORDINATE_PATTERN = '/\A(?:0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)\z/D';
    private const MAX_OLD_KEYFRAMES_BYTES = 16384;
    private const ASPECT_RATIOS = ['original', '9:16', '1:1', '16:9', '4:5'];
    private const REFRAME_MODES = ['original', 'center', 'manual', 'auto'];

    /** @var callable(int, int): ?array */
    private $project;

    /** @var callable(int, int): array */
    private $clips;

    /** @var callable(int): ?array */
    private $user;

    /** @var callable(int): bool */
    private $consent;
    /** @var callable(int,int): ?int */
    private $recoveryToken;

    /** @var array{max_duration_ms:int,max_frames:int,max_edge:int} */
    private array $reframeUiConfig;

    public function __construct(
        private View $view,
        callable $project,
        callable $clips,
        ?callable $user = null,
        private ?ErrorHandler $errors = null,
        ?callable $consent = null,
        array $reframeConfig = [],
        ?callable $recoveryToken = null
    ) {
        $this->project = $project;
        $this->clips = $clips;
        $this->user = $user ?? static fn (): ?array => null;
        $this->consent = $consent ?? static fn (): bool => false;
        $this->recoveryToken = $recoveryToken ?? static fn (): ?int => null;
        $durationSeconds = $this->boundedInteger(
            $reframeConfig['reframe_max_duration_seconds'] ?? null,
            90,
            1,
            180
        );
        $this->reframeUiConfig = [
            'max_duration_ms' => $durationSeconds * 1000,
            'max_frames' => $this->boundedInteger(
                $reframeConfig['reframe_preview_max_frames'] ?? null,
                180,
                2,
                180
            ),
            'max_edge' => $this->boundedInteger(
                $reframeConfig['reframe_preview_max_edge'] ?? null,
                320,
                64,
                320
            ),
        ];
        $this->errors ??= new ErrorHandler();
    }

    /** @param array<string, string> $parameters */
    public function show(Request $request, array $parameters): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) {
            return Response::redirect('/login');
        }
        $rawId = (string) ($parameters['id'] ?? '');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $rawId) !== 1) {
            return $this->errors->renderStatus(404);
        }
        $projectId = (int) $rawId;
        if ($projectId < 1) {
            return $this->errors->renderStatus(404);
        }
        $rawProject = ($this->project)($projectId, $userId);
        if (!is_array($rawProject)) {
            return $this->errors->renderStatus(404);
        }
        $status = (new ProjectStatusService(static fn (): array => $rawProject))
            ->forOwnedProject($projectId, $userId);
        if ($status === null) {
            return $this->errors->renderStatus(404);
        }

        $showSuggestions = $status['analysis_status'] === 'completed';
        $clips = $showSuggestions
            ? ($this->clips)($projectId, $userId)
            : [];
        if (!is_array($clips)) {
            $clips = [];
        }
        $feedback = Session::pull('clip_render_feedback');
        $errors = Session::pull('clip_render_errors', []);
        $old = Session::pull('clip_render_old', []);
        $consentActive = (bool) ($this->consent)($userId);

        return $this->view->render('projects.show', [
            'title' => 'Projetos',
            'user' => $this->userData($userId),
            'project' => [
                'id' => $projectId,
                'name' => (string) ($rawProject['name'] ?? 'Projeto sem nome'),
                'status' => $status['status'],
                'progress' => $status['progress'],
                'stage' => $status['stage'],
                'message' => $status['message'],
                'analysis_status' => $status['analysis_status'],
                'video_summary' => ($rawProject['video_summary'] ?? null) === null
                    ? null
                    : (string) $rawProject['video_summary'],
                'updated_at' => (string) ($rawProject['updated_at'] ?? ''),
            ],
            'clips' => $this->publicClips($clips),
            'analysisResumeFeedback' => Session::pull('analysis_resume_feedback'),
            'analysisRecoveryToken' => $status['status'] === 'failed' ? ($this->recoveryToken)($projectId,$userId) : null,
            'clipRenderFeedback' => is_string($feedback) ? $feedback : null,
            'clipRenderErrors' => $this->renderErrors($errors),
            'clipRenderOld' => $this->renderOld($old),
            'mediaPipeConsentActive' => $consentActive,
            'reframeUiConfig' => $this->reframeUiConfig,
        ]);
    }

    /** @param array<int, mixed> $rows @return list<array{id:int,title:string,start_time:float,end_time:float,duration_seconds:float,viral_score:int,hook:string,reason:string,category:string,status:string,render_start_time:?float,render_end_time:?float,output_aspect_ratio:string,reframe_mode:string,source_preview_url:string}> */
    private function publicClips(array $rows): array
    {
        $public = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) < 1) {
                continue;
            }
            $public[] = [
                'id' => (int) $row['id'],
                'title' => (string) ($row['title'] ?? ''),
                'start_time' => (float) ($row['start_time'] ?? 0),
                'end_time' => (float) ($row['end_time'] ?? 0),
                'duration_seconds' => (float) ($row['duration_seconds'] ?? 0),
                'viral_score' => max(0, min(100, (int) ($row['viral_score'] ?? 0))),
                'hook' => (string) ($row['hook'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'status' => (string) ($row['status'] ?? 'suggested'),
                'render_error_message' => is_string($row['render_error_code'] ?? null)
                    ? \App\Queue\ProcessingErrorCatalog::message($row['render_error_code']) : null,
                'render_start_time' => ($row['render_start_time'] ?? null) === null ? null : (float) $row['render_start_time'],
                'render_end_time' => ($row['render_end_time'] ?? null) === null ? null : (float) $row['render_end_time'],
                'output_aspect_ratio' => $this->allowedString(
                    $row['output_aspect_ratio'] ?? null,
                    self::ASPECT_RATIOS,
                    'original'
                ),
                'reframe_mode' => $this->allowedString(
                    $row['reframe_mode'] ?? null,
                    self::REFRAME_MODES,
                    'original'
                ),
                'source_preview_url' => '/clips/' . (int) $row['id'] . '/source-preview',
            ];
        }

        return $public;
    }

    /** @return array<string, string> */
    private function renderErrors(mixed $errors): array
    {
        if (!is_array($errors)) {
            return [];
        }
        $public = [];
        foreach (['start_time', 'end_time', 'reframe', 'form'] as $field) {
            if (isset($errors[$field]) && is_string($errors[$field])) {
                $public[$field] = $errors[$field];
            }
        }

        return $public;
    }

    /** @return array{clip_id:int,start_time:string,end_time:string,aspect_ratio:string,reframe_mode:string,focus_x:string,focus_y:string,reframe_keyframes?:string}|array{} */
    private function renderOld(mixed $old): array
    {
        if (!is_array($old) || !is_int($old['clip_id'] ?? null) || $old['clip_id'] < 1) {
            return [];
        }

        $aspectRatio = $old['aspect_ratio'] ?? 'original';
        if (!is_string($aspectRatio) || !in_array($aspectRatio, self::ASPECT_RATIOS, true)) {
            $aspectRatio = 'original';
        }
        $mode = $old['reframe_mode'] ?? 'original';
        if (!is_string($mode) || !in_array($mode, self::REFRAME_MODES, true)) {
            $mode = 'original';
        }
        $public = [
            'clip_id' => $old['clip_id'],
            'start_time' => $this->oldPattern($old['start_time'] ?? null, self::DECIMAL_PATTERN),
            'end_time' => $this->oldPattern($old['end_time'] ?? null, self::DECIMAL_PATTERN),
            'aspect_ratio' => $aspectRatio,
            'reframe_mode' => $mode,
            'focus_x' => $this->oldPattern($old['focus_x'] ?? '', self::COORDINATE_PATTERN, true),
            'focus_y' => $this->oldPattern($old['focus_y'] ?? '', self::COORDINATE_PATTERN, true),
        ];
        $keyframes = $old['reframe_keyframes'] ?? null;
        if (is_string($keyframes) && strlen($keyframes) <= self::MAX_OLD_KEYFRAMES_BYTES) {
            $public['reframe_keyframes'] = $keyframes;
        }

        return $public;
    }

    private function oldPattern(mixed $value, string $pattern, bool $acceptEmpty = false): string
    {
        if (!is_string($value) || ($value === '' && !$acceptEmpty)) {
            return '';
        }

        return ($value === '' && $acceptEmpty) || preg_match($pattern, $value) === 1 ? $value : '';
    }

    /** @param list<string> $allowed */
    private function allowedString(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if (!is_int($value)) {
            return $default;
        }

        return max($minimum, min($maximum, $value));
    }

    /** @return array{id:int,name:string,email:string,credits:int,plan_name:string,monthly_minutes:int} */
    private function userData(int $userId): array
    {
        $user = ($this->user)($userId);
        if (!is_array($user)) {
            return ['id' => $userId, 'name' => 'Conta', 'email' => '', 'credits' => 0, 'plan_name' => 'Plano', 'monthly_minutes' => 0];
        }

        return [
            'id' => $userId,
            'name' => (string) ($user['name'] ?? 'Conta'),
            'email' => (string) ($user['email'] ?? ''),
            'credits' => (int) ($user['credits'] ?? 0),
            'plan_name' => (string) ($user['plan_name'] ?? 'Plano'),
            'monthly_minutes' => (int) ($user['monthly_minutes'] ?? 0),
        ];
    }
}
