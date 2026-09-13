<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;

final class CsrfMiddleware
{
    private ErrorHandler $errors;

    public function __construct(?ErrorHandler $errors = null)
    {
        $this->errors = $errors ?? new ErrorHandler();
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $request->input('_token');
        $token = is_string($token) ? $token : $request->header('X-CSRF-Token');

        if (!Csrf::validate($token)) {
            return $this->errors->renderStatus(419);
        }

        return $next($request);
    }
}
