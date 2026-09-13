<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AdminMiddleware
{
    /** @var callable(int):array<string,mixed>|null */
    private $identityLookup;

    /** @param callable(int):array<string,mixed>|null $identityLookup */
    public function __construct(callable $identityLookup)
    {
        $this->identityLookup = $identityLookup;
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId <= 0) {
            return $this->private(Response::redirect('/login'));
        }

        $identity = ($this->identityLookup)($userId);
        if (!is_array($identity) || ($identity['status'] ?? null) !== 'active') {
            Session::forget('user_id');
            Session::forget('_csrf');

            return $this->private(Response::redirect('/login'));
        }

        if (($identity['role'] ?? null) !== 'admin') {
            return $this->private(Response::text('Acesso negado.', 403));
        }

        return $this->private($next($request));
    }

    private function private(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store, private');
    }
}
