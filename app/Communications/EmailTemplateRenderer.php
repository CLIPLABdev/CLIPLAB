<?php

declare(strict_types=1);

namespace App\Communications;

use InvalidArgumentException;

final class EmailTemplateRenderer
{
    /** @param array<string,string> $variables @param list<string> $allowed */
    public function renderSubject(string $template, array $variables, array $allowed): string
    {
        $this->validateTokens($template, $variables, $allowed);
        $subject = $this->renderTokens($template, $variables, $allowed, false);
        if ($subject === '' || str_contains($subject, "\r") || str_contains($subject, "\n") || mb_strlen($subject) > 255) {
            throw new InvalidArgumentException('Email subject is invalid.');
        }
        return $subject;
    }

    /** @param array<string,string> $variables @param list<string> $allowed */
    public function render(string $template, array $variables, array $allowed): string
    {
        (new EmailHtmlPolicy())->validate($template);
        $this->validateTokens($template, $variables, $allowed);
        $rendered = $this->renderTokens($template, $variables, $allowed, true);
        (new EmailHtmlPolicy())->validate($rendered);
        return $rendered;
    }

    public function renderText(string $template, array $variables, array $allowed): string
    {
        $this->validateTokens($template, $variables, $allowed);
        return $this->renderTokens($template, $variables, $allowed, false);
    }

    private function validateTokens(string $template, array $variables, array $allowed): void
    {
        if (mb_strlen($template) > 200000) {
            throw new InvalidArgumentException('Email template is too large.');
        }
        preg_match_all('/\{\{([a-z_]+)\}\}/', $template, $matches);
        foreach (array_unique($matches[1]) as $name) {
            if (!in_array($name, $allowed, true) || !array_key_exists($name, $variables)) {
                throw new InvalidArgumentException('Email template variable is invalid.');
            }
        }
        if (preg_match('/\{\{|\}\}/', preg_replace('/\{\{[a-z_]+\}\}/', '', $template) ?? '') === 1) {
            throw new InvalidArgumentException('Email template syntax is invalid.');
        }

    }

    /** @param array<string,string> $variables @param list<string> $allowed */
    private function renderTokens(string $template, array $variables, array $allowed, bool $html): string
    {
        return preg_replace_callback('/\{\{([a-z_]+)\}\}/', function (array $match) use ($variables, $html): string {
            $name = $match[1];
            $value = $variables[$name];
            if (str_starts_with($name, 'link_')) {
                $this->assertSafeLink($value);
            }
            return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
        }, $template) ?? '';
    }

    private function assertSafeLink(string $value): void
    {
        (new EmailHtmlPolicy())->validateUrl($value);
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true)))) {
            throw new InvalidArgumentException('Email link is invalid.');
        }
    }
}
