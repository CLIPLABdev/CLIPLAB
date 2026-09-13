<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\DashboardService;

final class DashboardController
{
    /** @var callable(): DashboardService */
    private $dashboardFactory;

    /** @var callable(): UserRepository */
    private $usersFactory;

    public function __construct(private View $view, DashboardService|callable $dashboard, UserRepository|callable $users)
    {
        $this->dashboardFactory = $dashboard instanceof DashboardService ? static fn (): DashboardService => $dashboard : $dashboard;
        $this->usersFactory = $users instanceof UserRepository ? static fn (): UserRepository => $users : $users;
    }

    public function index(): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId <= 0) {
            return Response::redirect('/login');
        }

        $user = $this->users()->findDashboardProfile($userId);
        if ($user === null) {
            return Response::redirect('/login');
        }

        $metrics = $this->dashboard()->forUser($userId);
        $user['credits'] = $metrics['credits'];

        return $this->view->render('dashboard.index', [
            'title' => 'Visão geral',
            'user' => $user,
            'metrics' => $metrics,
        ]);
    }

    private function dashboard(): DashboardService
    {
        return ($this->dashboardFactory)();
    }

    private function users(): UserRepository
    {
        return ($this->usersFactory)();
    }
}
