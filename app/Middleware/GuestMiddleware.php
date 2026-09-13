<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class GuestMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (Session::has('user_id')) {
            return Response::redirect('/dashboard');
        }

        return $next($request);
    }
}
