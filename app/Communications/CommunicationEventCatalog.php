<?php

declare(strict_types=1);

namespace App\Communications;

use InvalidArgumentException;

final class CommunicationEventCatalog
{
    /** @var array<string,array{category:string,transactional:bool,variables:list<string>,channels:list<string>}> */
    private const EVENTS = [
        'account.email_change_requested' => ['category' => 'account', 'transactional' => true, 'variables' => ['nome_usuario', 'link_confirmacao'], 'channels' => ['email']],
        'account.email_changed' => ['category' => 'account', 'transactional' => true, 'variables' => ['nome_usuario'], 'channels' => ['in_app', 'email']],
        'account.password_changed' => ['category' => 'account', 'transactional' => true, 'variables' => ['nome_usuario'], 'channels' => ['in_app', 'email']],
        'auth.welcome' => ['category' => 'account', 'transactional' => true, 'variables' => ['nome_usuario'], 'channels' => ['in_app', 'email']],
        'auth.email_verification' => ['category' => 'account', 'transactional' => true, 'variables' => ['nome_usuario', 'link_confirmacao'], 'channels' => ['email']],
        'auth.password_reset' => ['category' => 'account', 'transactional' => true, 'variables' => ['nome_usuario', 'link_recuperacao'], 'channels' => ['email']],
        'media.processing_completed' => ['category' => 'processing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_projeto'], 'channels' => ['in_app', 'email']],
        'media.processing_failed' => ['category' => 'processing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_projeto'], 'channels' => ['in_app', 'email']],
        'media.usage_limit_reached' => ['category' => 'usage', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano'], 'channels' => ['in_app', 'email']],
        'billing.payment_approved' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'billing.payment_pending' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'billing.payment_failed' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'billing.subscription_created' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'billing.subscription_renewed' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'billing.subscription_canceled' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'billing.subscription_changed' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'billing.refund_processed' => ['category' => 'billing', 'transactional' => true, 'variables' => ['nome_usuario', 'nome_plano', 'valor', 'moeda', 'proxima_cobranca', 'link_assinatura', 'link_pagamento', 'motivo'], 'channels' => ['in_app', 'email']],
        'marketing.campaign' => ['category' => 'marketing', 'transactional' => false, 'variables' => ['nome_usuario', 'titulo', 'conteudo'], 'channels' => ['email']],
    ];

    public function events(): array { return self::EVENTS; }

    /** @param array<string,mixed> $variables @return array{category:string,transactional:bool,variables:array<string,string>,channels:list<string>} */
    public function definition(string $event, array $variables): array
    {
        $definition = self::EVENTS[$event] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Communication event is not supported.');
        }

        $provided = array_keys($variables);
        sort($provided);
        $allowed = $definition['variables'];
        sort($allowed);
        if ($provided !== $allowed) {
            throw new InvalidArgumentException('Communication variables are invalid.');
        }

        $normalized = [];
        foreach ($definition['variables'] as $name) {
            $value = $variables[$name];
            if (!is_string($value) || mb_strlen($value) > 4000) {
                throw new InvalidArgumentException('Communication variable is invalid.');
            }
            $normalized[$name] = $value;
        }

        return ['category' => $definition['category'], 'transactional' => $definition['transactional'], 'variables' => $normalized, 'channels' => $definition['channels']];
    }
}
