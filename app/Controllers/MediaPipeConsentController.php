<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\MediaPipeConsentService;
use Throwable;

final class MediaPipeConsentController
{
    /** @var callable(int, int): bool */
    private $ownedProject;

    public function __construct(
        private MediaPipeConsentService $consents,
        callable $ownedProject,
        private ?ErrorHandler $errors = null
    ) {
        $this->ownedProject = $ownedProject;
        $this->errors ??= new ErrorHandler();
    }

    public function grant(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        try {
            $returnPath = $this->returnPath($request, $userId);
        } catch (Throwable $exception) {
            return $this->errors->renderException($exception, false);
        }
        $this->consents->grant($userId);

        return Response::redirect($returnPath);
    }

    public function revoke(Request $request): Response
    {
        $userId = $this->userId();
        if ($userId === null) {
            return Response::redirect('/login');
        }

        try {
            $returnPath = $this->returnPath($request, $userId);
        } catch (Throwable $exception) {
            return $this->errors->renderException($exception, false);
        }
        $this->consents->revoke($userId);

        return Response::redirect($returnPath);
    }

    private function userId(): ?int
    {
        $userId = (int) Session::get('user_id', 0);

        return $userId > 0 ? $userId : null;
    }

    private function returnPath(Request $request, int $userId): string
    {
        $raw = $request->input('return_project_id');
        if (!is_string($raw) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $raw) !== 1) {
            return '/projetos';
        }
        $projectId = (int) $raw;
        if ($projectId < 1 || (string) $projectId !== $raw) {
            return '/projetos';
        }

        return ($this->ownedProject)($projectId, $userId) === true
            ? '/projetos/' . $projectId
            : '/projetos';
    }
}
