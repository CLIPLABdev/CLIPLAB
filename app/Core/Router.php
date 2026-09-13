<?php

declare(strict_types=1);

namespace App\Core;

use App\Media\UploadLimits;
use App\Middleware\CsrfMiddleware;
use RuntimeException;

final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable, middleware: array<int, mixed>, csrf: bool}> */
    private array $routes = [];

    private ErrorHandler $errors;
    private ?int $maximumRequestBytes;

    public function __construct(?ErrorHandler $errors = null, ?int $maximumRequestBytes = null)
    {
        $this->errors = $errors ?? new ErrorHandler();
        $this->maximumRequestBytes = $maximumRequestBytes ?? UploadLimits::runtime(PHP_INT_MAX)->phpPostMaxBytes();
        if ($this->maximumRequestBytes !== null && $this->maximumRequestBytes < 1) {
            throw new \InvalidArgumentException('The request size limit must be positive.');
        }
    }

    /** @param array<int, mixed> $middleware */
    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param array<int, mixed> $middleware */
    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * Registers an externally authenticated webhook endpoint. The handler is
     * responsible for validating the provider signature against Request::rawBody().
     * This is intentionally the only route category that bypasses browser CSRF.
     *
     * @param array<int, mixed> $middleware
     */
    public function webhookPost(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware, false);
    }

    public function dispatch(Request $request): Response
    {
        if ($this->requestExceedsMaximumSize($request)) {
            return $this->errors->renderStatus(413);
        }

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $parameters = $this->match($route['pattern'], $request->path());

            if ($parameters === null) {
                continue;
            }

            if ($route['method'] !== $request->method()) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $next = fn (Request $currentRequest): Response => $this->invoke($route['handler'], $currentRequest, $parameters);

            foreach (array_reverse($route['middleware']) as $middleware) {
                $next = $this->wrapMiddleware($middleware, $next);
            }

            return $route['csrf']
                ? (new CsrfMiddleware($this->errors))->handle($request, $next)
                : $next($request);
        }

        if ($allowedMethods !== []) {
            return Response::text('Method Not Allowed', 405)->withHeader('Allow', implode(', ', array_unique($allowedMethods)));
        }

        return $this->errors->renderStatus(404);
    }

    /** @param array<int, mixed> $middleware */
    private function add(string $method, string $pattern, callable $handler, array $middleware, bool $csrf = true): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => $middleware,
            'csrf' => $csrf,
        ];
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $expression = '';
        $offset = 0;

        if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $index => $match) {
                $expression .= preg_quote(substr($pattern, $offset, $match[1] - $offset), '#');
                $expression .= '(?P<' . $matches[1][$index][0] . '>[^/]+)';
                $offset = $match[1] + strlen($match[0]);
            }
        }

        $expression .= preg_quote(substr($pattern, $offset), '#');

        if (preg_match('#^' . $expression . '$#D', $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($matches as $name => $value) {
            if (is_string($name)) {
                $parameters[$name] = rawurldecode($value);
            }
        }

        return $parameters;
    }

    /** @param array<string, string> $parameters */
    private function invoke(callable $handler, Request $request, array $parameters): Response
    {
        $response = $handler($request, $parameters);

        if (!$response instanceof Response) {
            throw new RuntimeException('Route handlers must return a Response instance.');
        }

        return $response;
    }

    private function wrapMiddleware(mixed $middleware, callable $next): callable
    {
        if (is_string($middleware) && class_exists($middleware)) {
            $middleware = new $middleware();
        }

        if (is_object($middleware) && method_exists($middleware, 'handle')) {
            return fn (Request $request): Response => $middleware->handle($request, $next);
        }

        if (is_callable($middleware)) {
            return fn (Request $request): Response => $middleware($request, $next);
        }

        throw new RuntimeException('Route middleware must be callable or expose a handle method.');
    }

    private function requestExceedsMaximumSize(Request $request): bool
    {
        if ($this->maximumRequestBytes === null || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $contentLength = trim((string) $request->header('Content-Length', ''));
        if ($contentLength === '' || !ctype_digit($contentLength)) {
            return false;
        }

        $contentLength = ltrim($contentLength, '0');
        if ($contentLength === '') {
            return false;
        }

        $maximum = (string) $this->maximumRequestBytes;

        return strlen($contentLength) > strlen($maximum)
            || (strlen($contentLength) === strlen($maximum) && strcmp($contentLength, $maximum) > 0);
    }
}
