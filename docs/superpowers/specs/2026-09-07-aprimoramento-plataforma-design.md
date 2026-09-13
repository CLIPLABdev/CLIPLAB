# Aprimoramento integrado da plataforma — desenho aprovado

Origem: plano integral fornecido em 07/09/2026, attachment 88884d08-aea7-4e71-81be-3839f9e93de1. O usuário aprovou execução integral sem novas perguntas. Este desenho registra decisões de implementação, não declara funcionalidades concluídas.

## Restrições

- Fonte única: `C:/Users/Acer/Desktop/video`; preservar PHP 8/PDO, MariaDB, rotas, quotas, processamento e legendas existentes.
- Migrações aditivas, sem reseed, limpeza ou alteração de dados reais para QA. Testes unitários/SQLite separados do MariaDB em uso.
- Segredos cifrados, autorização por papel/ownership e CSRF em ações de navegador. Webhooks têm entrada explícita limitada e autenticação própria, nunca isenção global de CSRF.
- Nenhuma cobrança real, campanha externa ou publicação automática durante QA. Integrações não configuradas mostram indisponibilidade honesta. Não confundir testes locais com homologação externa.
- Snapshot anterior em `.local-history/platform-improvement-20260907/baseline`, sem .env ou mídia privada. Nenhum commit automático, reset ou worktree externo.

## Módulos e responsabilidades

1. Administração: melhorar CRUD, detalhe, filtros/paginação, métricas reais, configurações e mensagens promocionais segmentadas. Exclusão operacional é arquivamento reversível claramente identificado, preservando mídia, finanças e auditoria. Último administrador e autoarquivamento protegidos.
2. Billing: tabelas financeiras separadas do ledger de créditos; checkout próprio de revisão com coleta hospedada pelo Stripe/Pagar.me; preço/cupom congelado; idempotência persistente; confirmação autoritativa de pagamento; histórico, assinatura, cancelamento, cupons e financeiro.
3. Comunicações: catálogo de eventos, templates, central de notificações, preferências, campanhas opt-in e outbox cifrada com worker separado da fila de mídia. Reset e eventos de conta usam envio durável, sem tokens em logs.
4. Perfil: nome, confirmação segura de novo e-mail, troca de senha autenticada, avatar privado validado; informações reais de consumo/plano e atalhos financeiros. Desafio de e-mail possui hash, expira e é consumido uma vez; o endereço atual não muda antes da confirmação.
5. Integração visual e operacional: navegação compartilhada, estados vazios/erro/carregamento, acessibilidade e movimento reduzido; wiring dos módulos; migrações controladas; QA HTTP/UI e regressão de mídia; documentação de implantação Hostinger.

## Contratos compartilhados

- `SecretCipher::encrypt($plaintext, $aad = AAD_GEMINI)` e `decrypt($envelope, $aad = AAD_GEMINI)`: mesmo envelope v1 e default anterior, domínios distintos para billing e outbox.
- `App\Contracts\CommunicationEmitter::emit(int $userId, string $event, array $variables, string $dedupeKey, ?string $recipient = null, array $channels = ['in_app','email'], ?DateTimeImmutable $availableAt = null): void`. Emite duravelmente, não entrega síncrono; participa da transação do chamador sem confirmar transação alheia. Conteúdo sensível somente cifrado na outbox, nunca no payload in-app.
- Eventos de perfil: `account.email_change_requested` (novo destinatário, somente email), `account.email_changed` (aviso endereço anterior e in-app), `account.password_changed`.
- Billing mantém `users.plan_id` como entitlement operacional. Retorno de navegador nunca ativa plano. Pagamentos só contam após confirmação verificável. Eventos repetidos/fora de ordem e falhas parciais não duplicam efeitos.
- Configurações gerais não armazenam segredos; remetem às áreas específicas de Gemini, pagamentos e e-mails.

## Critério de conclusão

Cada recurso precisa de teste de comportamento e integração correspondente. Dados exibidos são consultas reais ou estado explícito de indisponibilidade. Testes de pagamento sandbox e entrega em inbox exigem credenciais/contas/callback HTTPS configurados; enquanto ausentes serão documentados como não homologados, sem simular aprovação.
