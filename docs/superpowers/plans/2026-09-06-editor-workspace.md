# Biblioteca e editor — Implementation Plan
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox syntax.
**Goal:** Templates/brand kit persistidos e editor visual sincronizado.
**Architecture:** Biblioteca isolada; editorprogressivo consome cópias normalizadas, mantém POST e SRTfallback.
**Tech Stack:** PHP8/MySQL,CSS,JS/Canvas locais.
**Spec:** docs/superpowers/specs/2026-09-06-real-video-homologation-design.md

## Global Constraints
- Trabalhar somente no worktree fase1-worktree existente; preservar alterações anteriores, .env, segredos, planos e arquivos reais. Não commit/push/merge/deploy.
- PHP 8.0/MySQL existentes; CSS/JS locais, CSP atual sem unsafe-inline. Não trocar stack nem adicionar rede/IA fictícia.
- Testes de unidade/feature usam clipforge_phase5_test; nunca DROP/TRUNCATE banco real. Migrações aditivas novas; não editar migrações aplicadas.
- Toda leitura/gravação é por proprietário ativo; POST com CSRF; 404 uniforme para recursos alheios; saídas privadas no-store; texto escapado.
- Nunca receber caminho, URL de logo, filtergraph, argumentos FFmpeg ou dimensões arbitrárias do cliente. IDs/valores allowlisted; limites de tamanho/tempo; ProcessRunner com array.
- Renderer consome snapshots imutáveis. Mudanças em template/brand não alteram jobs anteriores. Originais continuam preservados.
- Nenhuma chamada Gemini/download externo/publicação por subagentes; root conduz teste autorizado com YouTube 7YC9tf-qmmw. Não imprimir segredos.
- TDD, teste real do componente e relatório com RED/GREEN. Não alterar teste apenas para ocultar regressão. Revisão pelo root após entrega.

### Task 1: Biblioteca owned de templates, marca e logo
**Files:** novos app/Media/Editor/EditorTemplateCatalog.php, app/Repositories/EditorLibraryRepository.php, app/Services/EditorLibraryService.php, app/Controllers/EditorLibraryController.php, app/Views/editor-library/*.php, public/assets/css/editor-library.css, public/assets/js/editor-library.js se necessário, routes/editor-library.php, database/migrations/202609060045_create_editor_library.sql e testesUnit/Feature. Não editar routes/web.php,layouts/app.php,bin/process-jobs.php (root).
**Interfaces:** EditorTemplateCatalog::all():array gera5presets (viral,podcast,clean,impact,custom) com options exatas da spec; todos compatíveis EditorOptions. Repo com listOwned(userId),findOwned(id,userId),saveOwned(userId,?id,name,category,EditorOptions,aspectRatio),removeOwned(id,userId),kitForUser(userId),saveKit(...). Logo: findLogoOwned(assetId,userId):?array, resolveLogoForProject(assetId,projectId):?string retornaobjectkeyapósjoinownership, nãoexporpathsHTTP. Catálogo HTTP GET /api/editor-library sempreprivate/auth com options normalizadas e logo URLs owned; GET/POST /templates e /marca; POST /marca/logo; GET /marca/logos/{id}. Routefactorylazypara testes semDB.
- [ ] RED/GREEN DBisolado ownerforeign404, CSRF, bounds50templates/5logos, name80, fonteallowlist, no filter fields; update/removecomIDowned. Table FKs compatíveis usersid existente; migrationaditiva semaplicarreal.
```php
self::assertNull($repository->findOwned($templateId,$otherUserId));
self::assertNull($repository->resolveLogoForProject($logoId,$foreignProjectId));
```
- [ ] Kit salvadefaults/favoritesowned; template snapshotnãoincluiintervalo/SRT/keyframes. PNG<=2MiB <=2048 cada dimensão, magic/finfo/getimagesize validam raster e nãoSVG, private randomobjectkey; bytesimutáveis,semdelete deativosreferenciados. Limitarquotaadicional usandoaccountlock e bytesbrand; indique contrato para root integrar storageUsed sepreciso.
- [ ] Interface grafite/lima reutilizatokens, opçõesreais, templates visual previewsem provider, salvar/editar/excluir/aplicar; uploadlogocomfeedback e readprivate. JSONsafeescaping. Não alterar EditorOptions/renderer/ClipEditService (outro dono).
- [ ] Testar persistência/refresh, noJS, foreignasset, invalidPNG semgravar, rate/sizecaps. Relatório task-1-report.md na pasta desteplan.

### Task 2: Editor visual, timeline e integração biblioteca
**Files:** app/Controllers/ClipEditorController.php,app/Views/clips/editor.php,public/assets/js/clip-editor.js,public/assets/css/clip-editor.css; criar módulos pequenos editor-timeline.js/editor-output-preview.js conforme necessário; testsBrowser/Feature/Unit. Rootdono routes/editor.php. Não modificar media/options/ASS/repo da library.
**Interfaces:** consumir chavesEditorOptions novas da spec, catálogo GET /api/editor-library, logo ownedURL /marca/logos/{id}, links /clips/{id}/thumbnail-studio e /clips/{id}/publicacao. Formulário mantém names,requestkey,CSRF e cloneexport. FIELDS/opções scalarparse allowlist, camposomitidos usamdefaultsbackcompat. APIworddata exposta apenas paraowner se necessário via viewJSONsafe, nunca paths/segredos.
- [ ] RED navegadorcueseek/revisão/sync e templateapply persistido noform, sem submeterspontaneamente. FormfallbackSRTnoJS permanece.
```js
await page.getByRole('button',{name:/ir para legenda 2/i}).click();
await expect.poll(()=>page.locator('video').evaluate(v=>v.currentTime)).toBeGreaterThan(1);
```
- [ ] Construir cuelist/timeline com text/start/endedit, deletarlegenda explicitamente semcortaráudio, selecionarfrase ajustarintervalo comaviso. SRTsincronizado, seleçãoativa emtimeupdate; invaliddraftmantido. Desabilitar controles que disputamvideo durante detecção; disparar input/change aoaplicartemplate/trim.
- [ ] PreviewcompostoCanvas a partir dovídeoreal,safezones,ratios,crop manual/auto interpolado,legendas/título/CTA/logo; labelaproximado.Nãoautocarregarmídiaprivada.Carregamentode logo privatecomfalhaexplicável nãoquebravídeo, reducedmotion affectsUI only.
- [ ] UI fonts/style/background/outline/shadow/animation/logoposition conforme rendererreal; presetsfavorites aplicar/salvar via library comfeedback; opçãoSemauto.Routesnovaslinks nãofakestatus.
- [ ] Browser320/360/768/1440 + seekcomvideofixturetocável, noJSexport, invalidSRTpreserved, foreignsafe JSON, previewlinkthumb/publicação, testsFocused. Reporttask-2.
