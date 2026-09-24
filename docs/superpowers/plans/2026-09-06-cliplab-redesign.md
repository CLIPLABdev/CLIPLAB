# ClipLab redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox syntax for tracking.

**Goal:** Entregar a reformulação original da landing e experiência do SaaS preservando seus fluxos reais.
**Architecture:** PHP server-rendered existente, tokens CSS compartilhados, módulos de landing locais e catálogo público restrito com conteúdo aprovado pelo admin.
**Tech Stack:** PHP 8.0+, MySQL/MariaDB, CSS, JavaScript sem frameworks novos, PHPUnit e Playwright existentes.
**Spec:** docs/superpowers/specs/2026-09-06-cliplab-redesign-design.md

## Global Constraints
- Preservar PHP/MySQL, Hostinger como destino e localhost 127.0.0.1:8093.
- Marca ClipLab; paleta #0b0d10, #12161b, #191f26, #303942, #f4f6f8, #aab4c0, #d2f86b, #142009, #8cdce8.
- Sem novas dependências, segredos expostos, alterações de .env, dados pessoais públicos, métricas/depoimentos inventados ou publicação automática anunciada.
- Preservar POST, CSRF, IDs/names/data-* dos formulários, CSP sem unsafe-inline e lógica funcional existente.
- Alterações somente no worktree fase1-worktree; não commit/push/merge nem sobrescrever mudanças anteriores. Relatórios persistidos.

### Task 1: Sistema visual e jornada interna
**Files:** Create public/assets/css/design-system.css; modify app/Views/layouts/app.php, admin.php (somente stylesheet/marca, não array nav), auth/*.php, dashboard/index.php e textos relevantes de projects, clips, account, profile, informational/errors; teste tests/Feature/DesignSystemPresentationTest.php.
**Interfaces:** produz tokens e apresentação global. Não editar home.php, layouts/marketing.php, routes, backend, scripts de intake/Gemini; não editar partials marketing-*.
- [x] Escrever teste renderizando views e verificando inclusão compartilhada e links de próximo passo no empty state; observar falha por ausência do asset/CTA.
```php
$html = (new \App\Core\View())->render('auth/login', ['errors'=>[], 'old'=>[], 'message'=>null])->body;
```
Adaptar ao acesso real de Response já usado em testes existentes; testar DOM, não texto de arquivos.
- [x] Criar folha com tokens, controles, foco, estados, breakpoints e reduced-motion; incluir depois do CSS principal por layout. Aplicar a jornada: dashboard primeiro vídeo e retomada; estados vazios orientam ação real; login/cadastro com identidade e valor claro; projetos/clipes/admin/conta com escala consistente. Preservar labels funcionais e hooks.
- [x] Executar teste focado e testes de apresentação existentes; registrar RED/GREEN. Sem chamadas externas.
- [x] Ler diff e escrever .superpowers/sdd/2026-09-06-cliplab-redesign/task-1-report.md.

### Task 2: Landing original demonstrável
**Files:** Modify app/Views/home.php, layouts/marketing.php, public/assets/js/app.js; create public/assets/css/landing.css, public/assets/js/landing-demo.js e tests/Browser/landing-demo.test.js, tests/Feature/LandingPresentationTest.php.
**Interfaces:** consome tokens Task1; variáveis $publicPlans/$publicTestimonials arrays vazios por padrão. Inclui components/marketing-plans.php e marketing-proof.php quando existirem; Task3 é dono deles. Card classes definidas na spec.
- [x] Escrever teste antes do demo: avançar/voltar muda etapa visível e aria-current, reduced-motion não impede controles; nenhuma chamada externa nem submissão falsa. Verificar RED com script ausente, após fixture válida. Teste DOM PHP para CTA cadastro, headings e FAQ.
```js
// Exercitar o JS real em DOM leve existente ou Playwright; assert etapa ativa após clique.
await page.getByRole('button', {name: /próxima etapa/i}).click();
await expect(page.locator('[data-demo-stage="2"]')).toBeVisible();
```
- [x] Criar hero forte e original, demo acessível explicitamente ilustrativo, problema, etapas, produto, benefícios, planos include, prova include, FAQ e CTA. Copy curta, PT-BR, sem promessas não implementadas. Levar o usuário ao cadastro e /projetos/novo após entrar, sem fakeinput.
- [x] CSS responsivo próprio com boa hierarquia e identidade editorial; animar só interface de modo opcional. Melhorar menu móvel Escape/foco se necessário.
- [x] Testes focados e relatório .superpowers/sdd/2026-09-06-cliplab-redesign/task-2-report.md.

### Task 3: Planos reais e prova social administrável
**Files:** HomeController.php, routes/web.php/admin.php, novos serviço/repositorio/controller de MarketingContent, app/Views/admin/content.php, components/marketing-plans.php e marketing-proof.php, entrada navegação no array layouts/admin.php, testes Unit/Feature correspondentes.
**Interfaces:** HomeController recebe factory opcional para manter testes semDB; render home com publicPlans, publicTestimonials. Cada plan contém id,name,slug,price_cents,monthly_minutes,credits,features normalizadas pelo PlanLimits existente. Testimonial contém name,context,quote e só passa a público com published=true e autorização confirmada; maximum3. Admin rota GET/POST /admin/conteudo protegida pelo middleware existente e CSRF.
- [x] Examinar persistência config existente (app_settings/gemini_settings) e catálogo AccountRepository. Criar testes para ativo/inativo, vazio, sanitização/escape, autorização admin/CSRF, publicação autorizada e rejeição de dados incompletos/longos.
```php
self::assertSame([], $service->publicTestimonials()); // banco sem configuração de conteúdo
// Salvar testemunho sem autorização deve falhar e manter estado anterior.
```
- [x] Implementar módulo separado de segredos reutilizando armazenamento de settings se seguro, senão tabela aditiva/migração idempotente. Não alterar dados de planos ou credenciais. Saída pública somente whitelist, limites atuais normalizados; falha de DB não inventa preços.
- [x] Construir partials server-rendered com dados escapados, plano recomendado editorialmente sem falsificar popularidade; sem checkout fake. Admin com campos textuais simples até3, autorização explícita e toggle publicado; vazio oculta seção pública.
- [x] Executar testes focados sem DDL destrutivo e relatório .superpowers/sdd/2026-09-06-cliplab-redesign/task-3-report.md.

### Task 4: Integração e revisão
**Files:** tests/Browser/redesign.spec.mjs e config quando necessário; docs/VERIFICACAO_REDESIGN.md.
**Interfaces:** todas as entregas; não consumir Gemini.
- [x] Verificar HTTP das rotas principais, assets e links; navegador desktop/mobile, login, CTA, demo, FAQ, admin, responsividade, CSP.
- [x] Executar Unit,Feature em cliplab_phase5_test (não testes destrutivos); frontend intake e Gemini regressions.
- [x] Revisão independente das três tarefas e revisão final; corrigir achados importantes com testes antes da entrega.
- [x] Registrar evidências e pendências reais de produção; exibir landing no localhost. Não alegar implantação Hostinger.

## Fechamento — 6 de setembro de 2026

Quatro tarefas concluídas no escopo do redesign. Evidências finais e limites de produção em docs/VERIFICACAO_REDESIGN.md. Worktree e alterações anteriores preservados; nenhuma implantação externa ou commit realizado.
