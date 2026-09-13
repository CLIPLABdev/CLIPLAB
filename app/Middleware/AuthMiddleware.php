<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;

final class AuthMiddleware
{
    /** @var callable(): UserRepository|null */
    private $usersFactory;

    private ?\Closure $adminLookup;

    public function __construct(UserRepository|callable|null $users = null, ?callable $adminLookup = null)
    {
        $this->usersFactory = $users instanceof UserRepository ? static fn (): UserRepository => $users : $users;
        $this->adminLookup = $adminLookup === null ? null : \Closure::fromCallable($adminLookup);
    }

    public function handle(Request $request, callable $next): Response
    {
        Session::forget('admin_navigation');
        if (!Session::has('user_id')) {
            return $this->unauthenticated($request);
        }

        $userId = (int) Session::get('user_id');
        if ($userId <= 0 || $this->usersFactory === null || !($this->usersFactory)()->isActiveById($userId)) {
            Session::forget('user_id');
            Session::forget('_csrf');

            return $this->unauthenticated($request);
        }

        // Navigation only; administrative actions always recheck the database role independently.
        Session::put('admin_navigation', $this->adminLookup !== null && ($this->adminLookup)($userId) === true);

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        if (str_starts_with($request->path(), '/api/')) {
            return Response::json(['error' => 'unauthenticated'], 401)->withHeader('Cache-Control', 'no-store');
        }

        return Response::redirect('/login');
    }
}
