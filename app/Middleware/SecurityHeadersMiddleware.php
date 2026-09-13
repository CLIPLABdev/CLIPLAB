<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

final class SecurityHeadersMiddleware
{
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; worker-src 'self'";

    public function handle(Request $request, callable $next): Response
    {
        $response=$next($request);
        $extraPolicy=$response->header('Content-Security-Policy');
        // CSP lists enforce every policy: additional restrictions cannot relax the global baseline.
        // https://www.w3.org/TR/CSP3/#multiple-policies
        $policy=self::CONTENT_SECURITY_POLICY;
        if (is_string($extraPolicy) && $extraPolicy!=='' && $extraPolicy!==$policy && !preg_match('/[\r\n]/',$extraPolicy)) {
            $policy.=', '.$extraPolicy;
        }
        return $response
            ->withHeader('Content-Security-Policy', $policy)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', $response->header('Referrer-Policy')==='no-referrer'?'no-referrer':'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
