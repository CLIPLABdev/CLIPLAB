# T3 — comunicações: núcleo durável

## Entregue nesta fatia

- Contrato `CommunicationEmitter`, incluindo cancelamento seguro por prefixo.
- Catálogo fechado para eventos de conta, mídia, marketing e os oito eventos de billing.
- Emissor SQLite/MySQL-compatível: outbox cifrada com AAD `clipforge:communications:v1`, dedupe, notificação in-app sem payload sensível e participação em transação externa sem commit.
- Worker próprio de e-mail com claim/lease, retry limitado, renderização com variáveis escapadas e descarte do payload cifrado após aceite do SMTP.
- Migração aditiva `202609070020_create_communications.sql`; não executada.
- `process-email.php`, separado da fila `media`.
- Endurecimento SMTP: log apenas local/teste, SMTP autenticado exige TLS/SSL, e o log não guarda corpo ou token.

## Integração do root

Factory para o perfil/billing:

```php
new \App\Communications\CommunicationEmitterService(
    $pdo,
    new \App\Security\SecretCipher((string) \App\Core\Env::get('APP_ENCRYPTION_KEY', '')),
    new \App\Communications\CommunicationEventCatalog()
)
```

O serviço propaga falhas de persistência/cifragem e não confirma transação já aberta. Portanto o chamador deve deixar a exceção abortar a mudança crítica.

## RED/GREEN

- `CommunicationEmitterContractTest`: RED interface inexistente; GREEN 1 teste/2 assertions.
- `CommunicationEventCatalogTest`: RED classe inexistente; GREEN 3/5.
- `CommunicationEmitterServiceTest`: RED classe inexistente; GREEN 2/8 (SQLite, transação externa, dedupe e payload cifrado).
- `EmailTemplateRendererTest`: RED classe inexistente; GREEN 2/2.
- `EmailOutboxWorkerTest`: RED classe inexistente; GREEN 1/3 (sink fake, sem rede).
- `MailerFactoryTest` + `LogMailerTest`: GREEN 5/12 após política TLS/log.

## Limites desta fatia

- Nenhuma migração, e-mail real, chamada SMTP, campanha ou teste MySQL foi executado.
- Ainda requer wiring do root em `routes/web.php`/layouts e controllers administrativos para edição/teste explícito de templates e SMTP. O worker usa fallback de configuração segura do ambiente até existir factory de settings administrativos cifrados.
- SMTP 250 representa aceite pelo servidor, não confirmação de inbox; bounces/complaints exigem provider/webhook configurado.

## Fatia de persistência e navegação

- Adicionados `CommunicationInboxService`, `CommunicationMailSettingsService`, controllers e `routes/communications.php`. A factory de rota recebe explicitamente `view`, `pdo`, `cipher`, `authenticated` e `admin_only` do root, sem editar `routes/web.php` ou layouts.
- Caminhos preparados: `/notificacoes`, `/preferencias`, `/admin/emails` e `/admin/email-configuracao`. Preferências somente controlam marketing; conta/billing continuam transacionais.
- SMTP salvo no banco cifra apenas a senha com AAD próprio e estado público não devolve ciphertext.
- Revisão do worker: leases vencidos voltam ao claim, marketing perde prioridade para transacionais, o evento cifrado é comparado ao registro, terminal `failed` apaga ciphertext e transições verificam lease por `rowCount`.
- Regressões adicionais: lease vencido, renderer e preferências passaram em SQLite; lint das rotas/controllers/views passou.

### Bloqueadores antes de ligar estas rotas no runtime

1. Incluir seed/instalação segura de templates padrão para cada evento; sem isso o worker faz retry e por fim falha.
2. Adicionar sanitização de HTML administrativo + preview sandboxado e endpoint de test-send explicitamente rate-limited. A UI atual não deve ser publicada sem esses dois controles.
