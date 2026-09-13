<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class MarketingContentPresentationTest extends TestCase
{
    public function testPlanPartialEscapesCatalogAndDoesNotCreateFakeCheckout(): void
    {
        $html = $this->render('marketing-plans.php', ['publicPlans' => [[
            'id' => 2,
            'slug' => 'pro',
            'name' => '<Plano Pro>',
            'price_cents' => 4900,
            'monthly_minutes' => 300,
            'credits' => 100,
            'features' => [
                'exports_hd' => true,
                'priority_processing' => true,
                'team_access' => true,
                'limits' => ['max_upload_bytes' => 524288000, 'storage_bytes' => 10737418240],
            ],
        ]]]);
        self::assertStringContainsString('landing-plan-grid', $html);
        self::assertStringContainsString('&lt;Plano Pro&gt;', $html);
        self::assertStringContainsString('R$ 49,00', $html);
        self::assertStringContainsString('300 minutos', $html);
        self::assertStringContainsString('100 créditos', $html);
        self::assertStringContainsString('500 MB por envio', $html);
        self::assertStringContainsString('10 GB de armazenamento', $html);
        self::assertStringContainsString('Editor e revisão de cortes', $html);
        self::assertStringContainsString('Legendas editáveis', $html);
        self::assertStringContainsString('Exportação em MP4', $html);
        self::assertStringContainsString('confirmada pela administração', $html);
        self::assertStringNotContainsString('Acesso de equipe', $html);
        self::assertStringNotContainsString('Processamento prioritário', $html);
        self::assertStringNotContainsString('Exportação em HD', $html);
        self::assertStringNotContainsString('<form', strtolower($html));
        self::assertStringNotContainsString('checkout', strtolower($html));
    }

    public function testProofPartialIsHiddenWhenEmptyAndEscapesPublishedContent(): void
    {
        self::assertSame('', trim($this->render('marketing-proof.php', ['publicTestimonials' => []])));
        $html = $this->render('marketing-proof.php', ['publicTestimonials' => [['name' => '<Ana>', 'context' => 'Podcast & educação', 'quote' => '<script>alert(1)</script>', 'result' => 'Caso <verificado>', 'source_url' => 'https://example.test/caso?a=1&b=2']]]);
        self::assertStringContainsString('landing-proof-grid', $html);
        self::assertStringContainsString('&lt;Ana&gt;', $html);
        self::assertStringContainsString('Podcast &amp; educação', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('Caso &lt;verificado&gt;', $html);
        self::assertStringContainsString('href="https://example.test/caso?a=1&amp;b=2"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /** @param array<string,mixed> $variables */
    private function render(string $file, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__, 2) . '/app/Views/components/' . $file;
        return (string) ob_get_clean();
    }
}
