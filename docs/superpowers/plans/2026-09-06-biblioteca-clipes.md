# Biblioteca global de clipes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar biblioteca /clips funcional com filtros, paginação e disponibilidade automática dos renders privados.
**Architecture:** Consulta somente leitura dos clipes existentes com ownership e análise atual; controller autenticado e view responsiva reutilizam status/download já disponíveis.
**Tech Stack:** PHP 8.0+, PDO/MySQL/MariaDB, CSS e JavaScript puros, PHPUnit e Playwright de desenvolvimento.
**Spec:** docs/superpowers/specs/2026-09-06-biblioteca-clipes-design.md

## Global Constraints
- PHP 8.0+, sem Node em produção, sem provedor externo ou cópia de mídia.
- Owner exclusivamente da sessão; análise atual e revisão exata; nenhum path/chave privada na projeção.
- GET /clips é somente leitura; prepared statements, escape HTML, Cache-Control: private, no-store.
- Testes DB usam exclusivamente cliplab_phase5_test; preservar .env e localhost 8093.
- Worktree compartilhado: cada implementador altera e stageia somente os paths atribuídos. Commits são coordenados pelo root.
- Ruling: backend e view podem ser implementados paralelamente após congelar este contrato; arquivos não se sobrepõem, atendendo ao pedido do usuário por agilidade/subagentes.

### Task 1: Consulta e rota autenticada
**Files:** criar app/Repositories/ClipLibraryRepository.php, app/Controllers/ClipLibraryController.php, tests/Integration/ClipLibraryRepositoryTest.php, tests/Unit/ClipLibraryControllerTest.php, tests/Feature/ClipLibraryRouteIntegrationTest.php; alterar routes/web.php.
**Interfaces:** as duas assinaturas e shape completo da spec são vinculantes. View é criada na Task 2; use View temporária isolada nos testes unitários se ainda não existir. Não editar layout/CSS/view nesta task.
- [x] Teste primeiro ownership/current analysis/filtros/paginação/shape no banco real, com fixtures e cleanup dos próprios IDs.
Exemplo do contrato:
```php
$page = $repository->paginateForUser($ownerId, 'completed', 1, 24);
self::assertSame([$completedClipId], array_column($page['items'], 'id'));
self::assertSame([], $repository->paginateForUser($foreignId, 'completed', 1, 24)['items']);
self::assertArrayNotHasKey('output_file', $page['items'][0]);
```
- [x] Rodar o teste, verificar a falha esperada de classe ausente.
- [x] Implementar a consulta com joins e projeção explícita, COUNT e SELECT consistentes, LIMIT/OFFSET inteiros limitados e page clamp antes da multiplicação. Aplicar EXPLAIN na consulta.
- [x] Implementar controller com sessão/normalização robusta e integrar rota via closures lazy como ProjectController. A view recebe library.
```php
$router->get('/clips', static fn (Request $request) => $clipLibrary->index($request), [$authenticated]);
```
- [x] Testar inputs adversariais, guest/auth/foreign, sem dependência de rede. Rodar PHPUnit focado e lint.
- [x] Commit apenas paths desta task após combinar com root; escrever relatório em .superpowers/sdd/2026-09-06-biblioteca-clipes/task-1-report.md.

### Task 2: Biblioteca responsiva e navegação
**Files:** criar app/Views/clips/index.php, public/assets/css/clips.css, tests/Feature/ClipLibraryViewTest.php; alterar app/Views/layouts/app.php, tests/Feature/AppLayoutAccessibilityTest.php.
**Interfaces:** view recebe title=Clipes, user e library exatamente conforme spec. Reusar clip-status.js sem alterá-lo.
- [x] Testar projeção de cards/vazio/filtros/paginação/escape com View->render:
```php
$response = (new View())->render('clips.index', ['title'=>'Clipes', 'user'=>$user, 'library'=>$library]);
self::assertStringContainsString('/clips?filter=completed', $response->body());
self::assertStringContainsString('data-clip-status-url="/api/clips/41/status"', $response->body());
self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
```
- [x] Implementar layout premium coerente com app.css: cabeçalho/total real, filtros acessíveis, cards com score/metadata, ações download/projeto, vazio/paginação. Sem números fictícios nem vídeo carregado.
- [x] Cards ativos usam data-clip-card, data-clip-status-url, data-clip-status, data-clip-message, data-clip-thumbnail e data-clip-download. Assets hidden sem src/href antes de completed; respeitar [hidden] no CSS.
- [x] Add Clipes na sidebar usando ícone existente e aria-current. Filtros/links devem funcionar sem JS; flex/grid min-width:0 e quebra de textos em 320px; focus-visible e reduced-motion.
- [x] Rodar Feature focado e layout existente; commit somente paths próprios após combinar com root; relatório .superpowers/sdd/2026-09-06-biblioteca-clipes/task-2-report.md.

### Task 3: Fluxo integrado, browser e documentação
**Files:** alterar tests/Integration/RenderedClipWorkflowTest.php, tests/Browser/smart-reframe-e2e.spec.mjs, README.md, docs/HOSTINGER.md.
**Interfaces:** /clips e library shape das Tasks 1/2. Reutilizar fixture assinada, mutex, cleanup e worker finito existentes.
- [x] No workflow real, após render completado consultar ClipLibraryRepository: item completed, flags true, duração/aspect/mode corretos, estrangeiro não vê. Não criar job/débito adicional para publicação.
- [x] E2E abre outra página autenticada /clips enquanto render queued, observa atualização automática para completed e assets; completed filter inclui item, foreign library o exclui.
- [x] Testar 320/768/1440 com cards visíveis; no-JS biblioteca completed navegável. Reusar credenciais isoladas da fixture; nunca alterar demo 8093.
- [x] Rodar gates alterados e suítes afetadas, registrar evidência; inspecionar screenshot local para QA de layout.
- [x] Documentar rota, filtros, assets privados e comportamento do polling; guia Hostinger mantém worker/cron existentes.
- [x] Commit e revisão final. Exibir /clips no localhost 8093 e verificar HTTP autenticado sem alterar dados de projetos.
