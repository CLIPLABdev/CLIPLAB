<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MarketingContentValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\MarketingContentRepository;

final class MarketingContentService
{
    private const MAX_TESTIMONIALS = 3;
    private const MAX_NAME = 100;
    private const MAX_CONTEXT = 160;
    private const MAX_QUOTE = 600;
    private const MAX_RESULT = 160;
    private const MAX_SOURCE_URL = 255;

    public function __construct(
        private AccountRepository $accounts,
        private MarketingContentRepository $content
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function publicPlans(): array
    {
        return array_map(static fn (array $plan): array => [
            'id' => $plan['id'],
            'name' => $plan['name'],
            'slug' => $plan['slug'],
            'price_cents' => $plan['price_cents'],
            'monthly_minutes' => $plan['monthly_minutes'],
            'credits' => $plan['credits'],
            'features' => $plan['features'],
            'description' => (string) ($plan['description'] ?? ''),
            'daily_credits' => (int) ($plan['daily_credits'] ?? 0),
        ], $this->accounts->activePlans());
    }

    /** @return list<array<string,string>> */
    public function publicTestimonials(): array
    {
        return array_map(static function (array $testimonial): array {
            $public = [
                'name' => (string) $testimonial['name'],
                'context' => (string) $testimonial['context'],
                'quote' => (string) $testimonial['quote'],
            ];
            foreach (['result', 'source_url'] as $optional) {
                $value = is_string($testimonial[$optional] ?? null) ? trim($testimonial[$optional]) : '';
                if ($value !== '') {
                    $public[$optional] = $value;
                }
            }
            return $public;
        }, $this->content->publicTestimonials());
    }

    /** @return list<array<string,mixed>> */
    public function testimonialsForAdmin(): array
    {
        return array_map(static fn (array $testimonial): array => [
            'slot' => (int) $testimonial['slot'],
            'name' => (string) $testimonial['name'],
            'context' => (string) $testimonial['context'],
            'quote' => (string) $testimonial['quote'],
            'result' => is_string($testimonial['result'] ?? null) ? $testimonial['result'] : '',
            'source_url' => is_string($testimonial['source_url'] ?? null) ? $testimonial['source_url'] : '',
            'authorization_confirmed' => (bool) $testimonial['authorization_confirmed'],
            'published' => (bool) $testimonial['published'],
        ], $this->content->testimonialsForAdmin());
    }

    /** @param array<int,mixed> $input */
    public function saveTestimonials(int $actorId, array $input): void
    {
        if (count($input) > self::MAX_TESTIMONIALS) {
            throw new MarketingContentValidationException('Cadastre no máximo três relatos.');
        }

        $validated = [];
        foreach (array_values($input) as $index => $testimonial) {
            $record = $index + 1;
            if (!is_array($testimonial)) {
                throw new MarketingContentValidationException('Revise os dados do relato ' . $record . '.', $record);
            }
            $name = $this->text($testimonial, 'name', $record);
            $context = $this->text($testimonial, 'context', $record);
            $quote = $this->text($testimonial, 'quote', $record);
            $result = $this->text($testimonial, 'result', $record);
            $sourceUrl = $this->text($testimonial, 'source_url', $record);
            $authorized = $this->checked($testimonial['authorization_confirmed'] ?? false);
            $published = $this->checked($testimonial['published'] ?? false);

            if ($name === '' && $context === '' && $quote === '' && $result === '' && $sourceUrl === '' && !$authorized && !$published) {
                continue;
            }
            foreach (['name' => $name, 'context' => $context, 'quote' => $quote] as $field => $value) {
                if ($value === '') {
                    $labels = ['name' => 'o nome', 'context' => 'o contexto', 'quote' => 'a citação'];
                    throw new MarketingContentValidationException(
                        'Preencha ' . $labels[$field] . ' do relato ' . $record . '.',
                        $record,
                        $field
                    );
                }
            }
            if (!$authorized) {
                throw new MarketingContentValidationException(
                    'Confirme a autorização do relato ' . $record . ' antes de salvar.',
                    $record,
                    'authorization_confirmed'
                );
            }
            $this->assertLength($name, self::MAX_NAME, $record, 'name', 'nome');
            $this->assertLength($context, self::MAX_CONTEXT, $record, 'context', 'contexto');
            $this->assertLength($quote, self::MAX_QUOTE, $record, 'quote', 'citação');
            $this->assertLength($result, self::MAX_RESULT, $record, 'result', 'resultado');
            $this->assertLength($sourceUrl, self::MAX_SOURCE_URL, $record, 'source_url', 'fonte');
            if ($sourceUrl !== '' && !$this->isSafeSourceUrl($sourceUrl)) {
                throw new MarketingContentValidationException(
                    'Informe uma fonte HTTPS válida no relato ' . $record . '.',
                    $record,
                    'source_url'
                );
            }

            $validated[] = [
                'slot' => count($validated) + 1,
                'name' => $name,
                'context' => $context,
                'quote' => $quote,
                'result' => $result,
                'source_url' => $sourceUrl,
                'authorization_confirmed' => true,
                'published' => $published,
            ];
        }

        $this->content->replaceTestimonials($actorId, $validated);
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key, int $record): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            throw new MarketingContentValidationException('Revise este campo do relato ' . $record . '.', $record, $key);
        }

        return trim($value);
    }

    private function checked(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function assertLength(string $value, int $maximum, int $record, string $field, string $label): void
    {
        if (mb_strlen($value) > $maximum) {
            throw new MarketingContentValidationException(
                'O campo ' . $label . ' do relato ' . $record . ' deve ter no máximo ' . $maximum . ' caracteres.',
                $record,
                $field
            );
        }
    }

    private function isSafeSourceUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
