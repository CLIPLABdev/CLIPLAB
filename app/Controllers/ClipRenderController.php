<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Exceptions\ClipRenderValidationException;
use App\Media\ClipRenderReceipt;
use App\Media\Reframe\ReframeSubmission;
use App\Services\ClipRenderRequestService;
use App\Services\ClipStatusService;

final class ClipRenderController
{
    private const MAX_OLD_KEYFRAMES_BYTES = 16384;
    private const REFRAME_INPUT_FIELDS = [
        'aspect_ratio',
        'reframe_mode',
        'focus_x',
        'focus_y',
        'reframe_keyframes',
    ];
    private const SERVER_OWNED_FIELDS = [
        'detector_version',
        'output_width',
        'output_height',
        'filtergraph',
        'ffmpeg_args',
    ];

    /** @var callable(int, int, string, string, ReframeSubmission): ?\App\Media\ClipRenderReceipt */
    private $requestRender;

    /** @param ClipRenderRequestService|callable(int, int, string, string, ReframeSubmission): ?\App\Media\ClipRenderReceipt $requests */
    public function __construct(
        ClipRenderRequestService|callable $requests,
        private ?ClipStatusService $statuses = null,
        private ?ErrorHandler $errors = null
    ) {
        $this->requestRender = $requests instanceof ClipRenderRequestService
            ? static fn (int $clipId, int $userId, string $start, string $end, ReframeSubmission $reframe) =>
                $requests->request($clipId, $userId, $start, $end, $reframe)
            : $requests;
        $this->errors ??= new ErrorHandler();
    }

    /** @param array<string, string> $parameters */
    public function store(Request $request, array $parameters): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) {
            return Response::redirect('/login');
        }
        $clipId = $this->clipId($parameters);
        if ($clipId === null) {
            return $this->errors->renderStatus(404);
        }
        $start = $this->inputString($request->input('start_time', ''));
        $end = $this->inputString($request->input('end_time', ''));
        $reframe = $this->reframeSubmission($request);
        try {
            $receipt = ($this->requestRender)($clipId, $userId, $start, $end, $reframe);
        } catch (ClipRenderValidationException $exception) {
            $projectId = $exception->projectId();
            if ($projectId === null) {
                return $this->errors->renderStatus(404);
            }
            Session::flash('clip_render_errors', $exception->errors());
            $old = [
                'clip_id' => $clipId,
                'start_time' => $start,
                'end_time' => $end,
                'aspect_ratio' => $reframe->aspectRatio(),
                'reframe_mode' => $reframe->mode(),
                'focus_x' => $reframe->focusX(),
                'focus_y' => $reframe->focusY(),
            ];
            if (strlen($reframe->keyframesJson()) <= self::MAX_OLD_KEYFRAMES_BYTES) {
                $old['reframe_keyframes'] = $reframe->keyframesJson();
            }
            Session::flash('clip_render_old', $old);
            Session::flash('clip_render_feedback', 'Revise o intervalo informado.');

            return Response::redirect('/projetos/' . $projectId);
        }
        if (!$receipt instanceof ClipRenderReceipt) {
            return $this->errors->renderStatus(404);
        }
        Session::flash('clip_render_feedback', $receipt->created()
            ? 'Renderização solicitada.'
            : 'Este corte já está na fila para renderização.');

        return Response::redirect('/projetos/' . $receipt->projectId());
    }

    /** @param array<string, string> $parameters */
    public function status(Request $request, array $parameters): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) {
            return Response::json(['error' => 'unauthenticated'], 401)->withHeader('Cache-Control', 'no-store');
        }
        $clipId = $this->clipId($parameters);
        $status = $clipId !== null && $this->statuses !== null
            ? $this->statuses->forOwnedClip($clipId, $userId)
            : null;

        return ($status === null
            ? Response::json(['error' => 'clip_not_found'], 404)
            : Response::json($status))
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $parameters */
    private function clipId(array $parameters): ?int
    {
        $raw = (string) ($parameters['id'] ?? '');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $raw) !== 1) {
            return null;
        }
        $id = (int) $raw;

        return $id > 0 && (string) $id === $raw ? $id : null;
    }

    private function inputString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function reframeSubmission(Request $request): ReframeSubmission
    {
        $hasReframeInput = false;
        foreach (self::REFRAME_INPUT_FIELDS as $field) {
            $hasReframeInput = $hasReframeInput || $request->hasInput($field);
        }
        $hasServerOwnedFields = false;
        foreach (self::SERVER_OWNED_FIELDS as $field) {
            $hasServerOwnedFields = $hasServerOwnedFields || $request->hasInput($field);
        }
        if (!$hasReframeInput && !$hasServerOwnedFields) {
            return ReframeSubmission::original();
        }

        return new ReframeSubmission(
            $this->inputString($request->input('aspect_ratio', '')),
            $this->inputString($request->input('reframe_mode', '')),
            $this->inputString($request->input('focus_x', '')),
            $this->inputString($request->input('focus_y', '')),
            $this->inputString($request->input('reframe_keyframes', '')),
            $hasServerOwnedFields
        );
    }
}
