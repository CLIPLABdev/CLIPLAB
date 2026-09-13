<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Communications\CommunicationEventCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CommunicationEventCatalogTest extends TestCase
{
    public function testBillingEventsExposeOnlyTheirDocumentedVariables(): void
    {
        $catalog = new CommunicationEventCatalog();

        $event = $catalog->definition('billing.payment_approved', [
            'nome_usuario' => 'Ana',
            'nome_plano' => 'Pro',
            'valor' => '29,90',
            'moeda' => 'BRL',
            'proxima_cobranca' => '2026-10-07',
            'link_assinatura' => 'https://app.example.test/conta',
            'link_pagamento' => 'https://pay.example.test/invoice',
            'motivo' => '',
        ]);

        self::assertSame('billing', $event['category']);
        self::assertTrue($event['transactional']);
        self::assertSame(['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], array_keys($event['variables']));
    }

    public function testRejectsEventsAndVariablesOutsideTheClosedCatalog(): void
    {
        $catalog = new CommunicationEventCatalog();

        $this->expectException(InvalidArgumentException::class);
        $catalog->definition('billing.payment_approved', ['nome_usuario' => 'Ana', 'token' => 'must-not-persist']);
    }

    public function testMarketingNeedsExplicitConsent(): void
    {
        $catalog = new CommunicationEventCatalog();

        self::assertFalse($catalog->definition('marketing.campaign', ['nome_usuario' => 'Ana', 'titulo' => 'Novidade', 'conteudo' => 'Confira'])['transactional']);
    }
}
