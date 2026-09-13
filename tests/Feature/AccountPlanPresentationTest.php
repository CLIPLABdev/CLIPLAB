<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class AccountPlanPresentationTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = []; }

    /** @dataProvider catalogFlags */
    public function testComparisonShowsEnforcedLimitsWithoutAdvertisingCatalogOnlyFlags(bool $enabled): void
    {
        $plan = [
            'id' => 2, 'name' => 'Pro', 'price_cents' => 1990, 'monthly_minutes' => 240,
            'credits' => 80, 'included_credits' => 80,
            'features' => [
                'exports_hd' => $enabled, 'priority_processing' => $enabled, 'team_access' => $enabled,
                'limits' => ['max_upload_bytes' => 512 * 1024 * 1024, 'storage_bytes' => 9 * 1024 * 1024 * 1024],
            ],
        ];
        $response = (new View())->render('account.plan', [
            'title' => 'Plano e limites',
            'user' => ['id' => 7, 'name' => 'Ana', 'email' => 'ana@example.invalid', 'credits' => 12, 'plan_name' => 'Pro'],
            'snapshot' => [
                'plan' => $plan, 'credits' => 12, 'minutes_used' => 40, 'minutes_remaining' => 200,
                'storage_bytes' => 1024, 'storage_remaining_bytes' => 9 * 1024 * 1024 * 1024 - 1024,
            ],
            'plans' => [$plan],
        ]);

        self::assertSame(200, $response->status());
        foreach (['Acesso para equipe', 'Processamento prioritário', 'Exportações em alta definição',
            'exports_hd', 'priority_processing', 'team_access', 'Inclui:', 'Não inclui:'] as $promise) {
            self::assertStringNotContainsString($promise, $response->body());
        }
        foreach (['Compare os limites', '240 min/mês', '80 créditos', 'Upload de até 512,0 MB',
            'Armazenamento de 9,0 GB', '40 de 240 minutos usados.', '200 restantes'] as $limit) {
            self::assertStringContainsString($limit, $response->body());
        }
        self::assertStringContainsString('cobrança online será disponibilizada futuramente', $response->body());
        self::assertStringContainsString('Alterações de plano são confirmadas pela administração.', $response->body());
    }

    public static function catalogFlags(): array
    {
        return ['enabled catalog flags' => [true], 'disabled catalog flags' => [false]];
    }

    public function testBillingEnabledShowsCheckoutAndPaymentHistoryInsteadOfFutureBillingCopy(): void
    {
        $free = ['id'=>1,'name'=>'Grátis','price_cents'=>0,'monthly_minutes'=>30,'credits'=>5,'included_credits'=>5,'features'=>['limits'=>['max_upload_bytes'=>1048576,'storage_bytes'=>10485760]]];
        $pro = ['id'=>2,'name'=>'Pro','price_cents'=>1990,'monthly_minutes'=>240,'credits'=>80,'included_credits'=>80,'features'=>['limits'=>['max_upload_bytes'=>1048576,'storage_bytes'=>10485760]]];
        $response = (new View())->render('account.plan', ['title'=>'Plano e limites','platformFeatures'=>['billing'=>true],'user'=>['id'=>7,'name'=>'Ana','email'=>'ana@example.invalid','credits'=>5,'plan_name'=>'Grátis'],'snapshot'=>['plan'=>$free,'credits'=>5,'minutes_used'=>0,'minutes_remaining'=>30,'storage_bytes'=>0,'storage_remaining_bytes'=>10485760],'plans'=>[$free,$pro]]);

        self::assertSame(200,$response->status());
        self::assertStringContainsString('/checkout/plano/2',$response->body());
        self::assertStringContainsString('/conta/pagamentos',$response->body());
        self::assertStringNotContainsString('cobrança online será disponibilizada futuramente',$response->body());
        self::assertStringNotContainsString('Alterações de plano são confirmadas pela administração.',$response->body());
    }
}
