# Editor Effects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox syntax for tracking.

**Goal:** Aplicar templates completos e oferecer efeitos que persistem e renderizam de verdade.
**Architecture:** EditorOptions centraliza contrato/defaults. Builder numérico cuida de filtros antes dos overlays. UI consome o contrato e aplica de forma atômica.
**Tech Stack:** PHP8.0, PDO/JSON, FFmpeg/libass, JavaScript canvas, PHPUnit, Playwright.
**Spec:** docs/superpowers/specs/2026-09-07-editor-effects.md

## Global Constraints

Trabalhar em C:/Users/Acer/Desktop/video, sem worktree externo, env, DB real, migrations, SMTP, publicação ou commits. Não remover funcionalidades. Defaults neutros preservam legado. Campos exatos e ranges na spec. SS RF, CSRF, ownership, limites, captions obrigatórias e consentimentos preservados. Sem filtros arbitrários, imagens externas ou temporária mutation de produção.

### Task 1: Contrato persistido e renderização

Brief integral .superpowers/sdd/2026-09-07-import-templates/backend-brief.md: arquivo único de requisitos do implementador, contendo arquivos, interfaces e exemplos RED/GREEN. Interface produzida EditorOptions::defaults/integerFields/fromForm, VideoEffectsFilterBuilder::build e variável previewTranscript.
- [ ] Executar todos passos TDD do brief e obter revisão independente de spec/qualidade.

### Task 2: Controles, template atômico e preview

Arquivos: app/Views/clips/editor.php, app/Views/editor-library/options.php (confirmar include existente), public/assets/js/editor-library.js, clip-editor.js, editor-output-preview.js; CSS próprio se necessário. Não editar backend da Task1.
Consome defaults e previewTranscript da Task1, produz form completo e JS preview. Para testes usar valores únicos da spec, por exemplo brightness=-25, contrast=125, animation_duration_ms=320, motion=zoom_in, zoom_percent=125.
- [ ] Escrever teste browser que seleciona template com esses valores, observa todos controles e formulário serializado, muda de template e confirma reset dos campos ausentes para defaults. Payload inválido não muda nenhum controle. Campo none legado mantém minimal.
```js
assert.equal(form.elements.brightness.value,'-25');
assert.equal(form.elements.zoom_percent.value,'125');
```
- [ ] Confirmar RED antes da UI nova; adicionar controles agrupados tipografia/legendas/animações/imagem/movimento com labels/unidades/help, estados de erro, teclado e viewport390.
- [ ] Implementar aplicação/salvamento de todos campos, emit após commit atômico; manter CSRF/reframe busy/rotas.
- [ ] Implementar preview de highlight/karaoke apenas com wordtimes correspondentes e opções animadas/efeitos conforme spec. Fallback recebe campos atuais; nenhum preview silenciosamente ignora efeito.
- [ ] Rodar testes JS existentes e novos, browser controlador real com SQLite/fixtures, salvar screenshot/evidências. Revisão independente.

### Task 3: Homologação e entrega

- [ ] Conferir schema→template→API→form→POST→snapshot com todos campos; executar render real incluindo legendas e reabrir template.
- [ ] Executar regressão Unit/Feature pertinente sem DB real e testes browser desktop/móvel/semJS/reducedmotion.
- [ ] Relatório técnico com achados/correções/limites, manter localhost, pacote sem segredos depois da revisão global.
