# Plano de execução — aprimoramento completo

**Desenho:** `docs/superpowers/specs/2026-09-07-aprimoramento-plataforma-design.md`.
**Estratégia:** módulos independentes em paralelo, TDD e revisão de integração. Todos os arquivos permanecem na pasta video.

## Tarefas e gates

- [x] T0. Ler especificação integral, auditar administração/billing/comunicações e preservar baseline sem segredos.
- [x] T1. Cifrador contextual retrocompatível: RED de isolamento de domínios; implementar; GREEN e regressão Gemini.
- [ ] T2. Billing (requisitos 5–8, 12): esquema/adapters/configuração, checkout idempotente, confirmação segura, assinatura/histórico, cupons, financeiro; testes locais de falhas/replays/ACL e documentação de homologação externa.
- [ ] T3. Comunicações (9–11, 14, parte 19): catálogo/outbox/worker, templates, preferências/notificações, campanhas opt-in, eventos de autenticação e segurança SMTP; testes de tokens, fila, dedupe, lease, templates e segmentação.
- [ ] T4. Administração (1–3, 13, parte 19): gestão/detalhe/arquivamento de usuários, planos, paginação persistente, métricas/alertas, settings e banners; testes de ACL, último admin, validação, isolamento e persistência.
- [x] T5. Perfil (4): serviço de segurança e migração, email confirmado, senha atual, avatar, telas/rotas e testes de duplicidade/token/ownership/uploads. Integração e ações reais validadas no MariaDB/navegador; SMTP externo permanece no gate operacional.
- [ ] T6. Integração (15–19): factories, menus, dashboard, atalhos de conta, branding, eventos de mídia e billing; loading/erro/empty states, responsividade e reduced motion.
- [ ] T7. Revisão dos módulos e fronteiras de segurança; corrigir achados com regressões dirigidas.
- [x] T8. Verificar/aplicar somente novas migrações aditivas, sem plans.sql ou alterações destrutivas. Preservar localhost e não duplicar worker de mídia. Sete migrações aplicadas com backups privados, incluindo 023 e baseline sem emissões retroativas.
- [ ] T9. QA real local: cadastro/conta/admin, persistência, checkout sem credenciais bloqueado honestamente, outbox local sem entrega externa, campanhas de fixtures, notificações, configurações e regressão de geração/legendas existentes.
- [ ] T10. Relatório requisito a requisito, resultados e limites externos, instruções Hostinger e pacote atualizado sem segredos.

## Propriedade de arquivos

- Root: SecretCipher; perfil e migração 202609070030; routes/web.php; layouts compartilhados; integração de factories/dashboard; testes integrados e docs.
- Billing: App/Billing, controllers/repositories/views específicos, routes/billing.php, migração 202609070010, Request/Router para webhook explícito e testes correspondentes. Não editar layouts/web.php/SecretCipher.
- Comunicação: App/Communications, CommunicationEmitter, novos controllers/views/rotas, migração 202609070020, Mailer/Auth/Reset e worker próprio. Não editar UserRepository/Profile/layouts/web.php.
- Admin: AdminRepository/Service/Controller/views, routes/admin.php, novos settings/banners, migração 202609070040 e CSS admin. Não editar layouts/web.php ou tabelas financeiras.

## Verificação

PHPUnit através de `C:/xampp/php/php.exe vendor/phpunit/phpunit/phpunit`, focado em SQLite/unit sem reset do banco real. Lint PHP/JS e diff check. Revisão compara baseline local porque a árvore anterior já era amplamente não rastreada. Nenhum dado real de usuário é alterado para satisfazer teste.
