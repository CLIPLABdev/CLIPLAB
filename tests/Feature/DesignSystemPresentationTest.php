<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class DesignSystemPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['_csrf' => str_repeat('a', 64)];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @dataProvider documentViews */
    public function testEveryDocumentDeclaresAnExistingFavicon(string $view, array $data): void
    {
        $dom = $this->dom((new View())->render($view, $data)->body());
        $icons = $dom->query('//head/link[@rel="icon"]');

        self::assertSame(1, $icons->length, $view . ' must declare its favicon to avoid the missing /favicon.ico fallback.');
        self::assertSame('/assets/images/favicon.svg', $icons->item(0)->getAttribute('href'));
        self::assertSame('image/svg+xml', $icons->item(0)->getAttribute('type'));
        self::assertFileExists(dirname(__DIR__, 2) . '/public' . $icons->item(0)->getAttribute('href'));
    }

    public static function documentViews(): array
    {
        $authentication = ['errors' => [], 'old' => [], 'message' => null, 'token' => 'test-token'];

        return [
            'marketing' => ['layouts.marketing', ['title' => 'ClipLab', 'content' => '']],
            'app' => ['layouts.app', [
                'title' => 'Visão geral', 'content' => '',
                'user' => ['id' => 17, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 7, 'plan_name' => 'Free', 'monthly_minutes' => 60],
            ]],
            'admin' => ['layouts.admin', [
                'title' => 'Administração', 'content' => '', 'flash' => null,
                'admin' => ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test'],
            ]],
            'login' => ['auth.login', $authentication],
            'register' => ['auth.register', $authentication],
            'forgot password' => ['auth.forgot-password', $authentication],
            'reset password' => ['auth.reset-password', $authentication],
            'informational' => ['informational.page', [
                'page' => 'terms', 'title' => 'Termos', 'introduction' => 'Termos de uso', 'sections' => [],
            ]],
            '404' => ['errors.404', []],
            '413' => ['errors.413', []],
            '419' => ['errors.419', []],
            '429' => ['errors.429', []],
            '500' => ['errors.500', ['correlationId' => 'favicon-regression', 'debug' => false, 'exception' => null]],
        ];
    }

    public function testAuthenticationSharesTheThemeAndProvidesAHomeLink(): void
    {
        foreach (['login', 'register', 'forgot-password', 'reset-password'] as $page) {
            $dom = $this->dom((new View())->render('auth.' . $page, [
                'errors' => [], 'old' => [], 'message' => null, 'token' => 'test-token',
            ])->body());
            self::assertSame(1, $dom->query('//head/link[@href="/assets/css/design-system.css"]')->length, $page);
            self::assertSame(1, $dom->query('//main//a[@href="/" and @aria-label]')->length, $page);
        }
    }

    public function testDashboardGuidesAnEmptyAccountToImportAndExplainsTheWorkflow(): void
    {
        $dom = $this->dashboard();
        self::assertSame(1, $dom->query('//head/link[@href="/assets/css/design-system.css"]')->length);
        self::assertSame(3, $dom->query('//ol[@aria-label="Etapas do seu projeto"]/li')->length);
        self::assertSame(1, $dom->query('//*[@class="empty-state"]//a[@href="/projetos/novo"]')->length);
        self::assertSame(1, $dom->query('//main//a[@href="/conta/plano"]')->length);
        self::assertSame(1, $dom->query('//main//a[@href="/conta/creditos"]')->length);
    }

    public function testAuthenticationStartsItsHeadingOutlineWithTheFormTitle(): void
    {
        foreach (['login', 'register'] as $page) {
            $dom = $this->dom((new View())->render('auth.' . $page, [
                'errors' => [], 'old' => [], 'message' => null,
            ])->body());
            $headings = $dom->query('//main//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]');
            self::assertSame('h1', $headings->item(0)?->nodeName, $page);
            self::assertSame(1, $dom->query('//main//h1')->length, $page);
        }
    }

    public function testDashboardKeepsReadyAnalysisReachableWithoutShowingFirstProjectOnboarding(): void
    {
        $dom = $this->dashboard([['id' => 31, 'name' => 'Entrevista', 'status' => 'suggestions_ready']]);
        self::assertSame(1, $dom->query('//main//a[@href="/projetos/31"]')->length);
        self::assertSame(0, $dom->query('//ol[@aria-label="Etapas do seu projeto"]')->length);
    }

    private function dashboard(array $recent = []): DOMXPath
    {
        return $this->dom((new View())->render('dashboard.index', [
            'title' => 'Visão geral',
            'user' => ['id' => 17, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 7, 'plan_name' => 'Free', 'monthly_minutes' => 60, 'status' => 'active'],
            'metrics' => ['projects' => count($recent), 'processed' => count($recent), 'minutes' => 60, 'minutes_used' => 0, 'credits' => 7, 'storage_bytes' => 0, 'recent' => $recent],
        ])->body());
    }

    public function testEmptyCreditHistoryOffersTheNextRelevantAction(): void
    {
        foreach (['all' => '/projetos/novo', 'consumption' => '/conta/creditos?filter=all'] as $filter => $href) {
            $dom = $this->dom((new View())->render('account.credits', [
                'title' => 'Créditos',
                'user' => ['id' => 17, 'name' => 'Ana', 'email' => 'ana@example.test', 'credits' => 7, 'plan_name' => 'Free', 'monthly_minutes' => 60],
                'snapshot' => ['credits' => 7],
                'ledger' => ['filter' => $filter, 'items' => [], 'total' => 0, 'pages' => 1, 'page' => 1],
            ])->body());
            self::assertSame(1, $dom->query('//*[@class="account-empty"]//a[@href="' . $href . '"]')->length, $filter);
        }
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML($html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new DOMXPath($dom);
    }
}
