<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class AdminWorkspacePresentationTest extends TestCase
{
    protected function setUp(): void { $_SESSION = ['_csrf' => str_repeat('a', 64)]; }
    protected function tearDown(): void { $_SESSION = []; unset($_SERVER['REQUEST_URI']); }

    public function testFeatureGatesAndNestedActiveRouteRemainNavigableWithoutJavascript(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/usuarios/17?tab=history';
        $dom = $this->render('layouts.admin', ['title'=>'Detalhes da conta','content'=>'', 'platformFeatures'=>['communications'=>true]]);
        self::assertSame('/admin/usuarios', $dom->query('//nav[@aria-label="Administração"]//a[@aria-current="page"]')->item(0)?->getAttribute('href'));
        self::assertSame(1, $dom->query('//nav//a[@href="/admin/emails"]')->length);
        self::assertSame(0, $dom->query('//nav//a[@href="/admin/financeiro" or @href="/admin/campanhas" or @href="/admin/promocoes"]')->length);
        self::assertSame(0, $dom->query('//aside[@hidden or @inert]')->length);
        self::assertSame(1, $dom->query('//button[@data-admin-drawer-toggle and @aria-controls="admin-navigation"]')->length);
        self::assertSame(1, $dom->query('//aside[@id="admin-navigation"]')->length);
        self::assertSame(1, $dom->query('//form[@action="/logout"]//input[@name="_token"]')->length);
    }

    public function testMetricsRetainTheirFinancialMeaningAndAttentionUsesTheActualFailureCount(): void
    {
        $dom = $this->render('admin.dashboard', ['title'=>'Administração', 'metrics'=>['users_total'=>21,'jobs_failed'=>3], 'financial'=>[
            'net_total_cents'=>120099,'net_month_cents'=>10099,'subscriptions_active'=>2,'subscriptions_total'=>4,'subscriptions_canceled'=>1,
            'payments_paid'=>6,'payments_pending'=>2,'payments_failed'=>1,
        ]]);
        self::assertSame(15, $dom->query('//section[contains(@class,"admin-metrics")]/article')->length);
        self::assertSame(1, $dom->query('//section[@aria-labelledby="admin-financial-title"]')->length);
        self::assertStringContainsString('R$ 1.200,99', $dom->document->textContent);
        self::assertStringContainsString('R$ 100,99', $dom->document->textContent);
        self::assertStringContainsString('pagamentos do mês UTC, menos seus reembolsos', $dom->document->textContent);
        self::assertSame(1, $dom->query('//a[@data-admin-attention and @href="/admin/erros"]')->length);
        self::assertStringContainsString('3', $dom->query('//a[@data-admin-attention]')->item(0)->textContent);
        $empty = $this->render('admin.dashboard', ['title'=>'Administração','metrics'=>[]]);
        self::assertSame(0, $empty->query('//*[@data-admin-attention]')->length);
        self::assertSame(0, $empty->query('//section[@aria-labelledby="admin-financial-title"]')->length);
    }

    private function render(string $view, array $data): DOMXPath
    {
        $html = (new View())->render($view, $data + ['admin'=>['name'=>'Admin','email'=>'admin@example.test'],'flash'=>null])->body();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="UTF-8">' . $html); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new DOMXPath($document);
    }
}
