<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    private Logger $logger;
    private View $view;

    public function __construct(?Logger $logger = null, ?View $view = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->view = $view ?? new View();
    }

    public function register(bool $debug): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $exception) use ($debug): void {
            $this->send($this->renderException($exception, $debug));
            exit(1);
        });

        register_shutdown_function(function () use ($debug): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            $this->send($this->renderException($exception, $debug));
        });
    }

    public function renderException(Throwable $exception, bool $debug): Response
    {
        $correlationId = bin2hex(random_bytes(16));
        $this->logger->error('Unhandled exception', [
            'correlation_id' => $correlationId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        return $this->renderView('errors.500', 500, [
            'correlationId' => $correlationId,
            'debug' => $debug,
            'exception' => $exception,
        ]);
    }

    public function renderStatus(int $status): Response
    {
        $supportedStatuses = [404, 413, 419, 429];

        if (!in_array($status, $supportedStatuses, true)) {
            return $this->renderView('errors.500', 500, [
                'correlationId' => null,
                'debug' => false,
                'exception' => null,
            ]);
        }

        return $this->renderView('errors.' . $status, $status);
    }

    /** @param array<string, mixed> $data */
    private function renderView(string $view, int $status, array $data = []): Response
    {
        $response = $this->view->render($view, $data);

        return Response::html($response->body(), $status);
    }

    private function send(Response $response): void
    {
        $response->send();
    }
}
