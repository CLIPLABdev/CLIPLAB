<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    private ?\Closure $contextProvider;

    public function __construct(private ?string $basePath = null, ?callable $contextProvider = null)
    {
        $this->basePath ??= dirname(__DIR__) . '/Views';
        $this->contextProvider = $contextProvider===null ? null : \Closure::fromCallable($contextProvider);
    }

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): Response
    {
        $relativePath = str_replace('.', '/', $view) . '.php';

        if (str_contains($relativePath, '..')) {
            throw new RuntimeException('Invalid view path.');
        }

        $path = $this->basePath . '/' . $relativePath;

        if (!is_file($path)) {
            throw new RuntimeException('View not found.');
        }

        if ($this->contextProvider!==null) {
            $context=($this->contextProvider)($view,$data);
            if (!is_array($context)) throw new RuntimeException('Invalid shared view context.');
            $data += $context;
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $path;

        return Response::html((string) ob_get_clean());
    }
}
