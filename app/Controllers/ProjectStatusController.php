<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\ProjectStatusService;

final class ProjectStatusController
{
    public function __construct(private ProjectStatusService $statuses)
    {
    }

    /** @param array<string, string> $parameters */
    public function show(Request $request, array $parameters): Response
    {
        $projectId = (string) ($parameters['id'] ?? '');
        $userId = (int) Session::get('user_id', 0);
        $status = ctype_digit($projectId) && $projectId !== '0'
            ? $this->statuses->forOwnedProject((int) $projectId, $userId)
            : null;

        return ($status === null
            ? Response::json(['error' => 'project_not_found'], 404)
            : Response::json($status))
            ->withHeader('Cache-Control', 'no-store');
    }
}
