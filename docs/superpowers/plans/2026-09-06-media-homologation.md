# Media e legendas — Implementation Plan
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox syntax.
**Goal:** Exportações reais Full HD com composição e transcrição reutilizável.
**Architecture:** Estender objetos de valor/snapshots existentes com compatibilidade retroativa.
**Tech Stack:** PHP8, FFmpeg/libass, PDO.
**Spec:** docs/superpowers/specs/2026-09-06-real-video-homologation-design.md

## Global Constraints
- Trabalhar somente no worktree fase1-worktree existente; preservar alterações anteriores, .env, segredos, planos e arquivos reais. Não commit/push/merge/deploy.
- PHP 8.0/MySQL existentes; CSS/JS locais, CSP atual sem unsafe-inline. Não trocar stack nem adicionar rede/IA fictícia.
- Testes de unidade/feature usam cliplab_phase5_test; nunca DROP/TRUNCATE banco real. Migrações aditivas novas; não editar migrações aplicadas.
- Toda leitura/gravação é por proprietário ativo; POST com CSRF; 404 uniforme para recursos alheios; saídas privadas no-store; texto escapado.
- Nunca receber caminho, URL de logo, filtergraph, argumentos FFmpeg ou dimensões arbitrárias do cliente. IDs/valores allowlisted; limites de tamanho/tempo; ProcessRunner com array.
- Renderer consome snapshots imutáveis. Mudanças em template/brand não alteram jobs anteriores. Originais continuam preservados.
- Nenhuma chamada Gemini/download externo/publicação por subagentes; root conduz teste autorizado com YouTube 7YC9tf-qmmw. Não imprimir segredos.
- TDD, teste real do componente e relatório com RED/GREEN. Não alterar teste apenas para ocultar regressão. Revisão pelo root após entrega.

### Task 1: Contrato de render e preservação da transcrição
**Files:** app/Media/Reframe/AspectRatio.php, app/Repositories/ClipRenderProfileRepository.php, app/Media/Editor/EditorOptions.php, app/Media/Subtitles/AssDocumentBuilder.php, app/Media/LocalFfmpegClipRenderer.php, app/Services/ClipEditService.php; criar helper App/Media/Subtitles/TranscriptRevision.php conforme necessário e testes Unit/Integration correspondentes.
**Interfaces:** novos fromString geram9:16=1080x1920,1:1=1080x1080,16:9=1920x1080,4:5=1080x1350. Método AspectRatio::fromStored(string value,?int width,?int height) valida somente combinações legadas720 e novas1080; repository usa-o para não invalidar histórico. Original continua null/null. Não aceitar output dims do HTTP.
EditorOptions novas chaves/defaults são exatos da spec, com getters camelCase. Renderer adiciona callable optional logoResolver ao final do construtor, (assetId,projectId)=>?objectKey; falha fechada quando logo solicitado sem resolução. Service adiciona optional logoOwned ao final, verifica antes de persistir. Root registra adapters no worker/routes posteriormente.
- [ ] RED: reconstruir/renderizar perfil720 pré-existente e criar novo1080; assert dims literais, rejeitar720x1282. Exemplo:
```php
self::assertSame(1080, AspectRatio::fromString('9:16')->outputWidth());
self::assertSame(720, AspectRatio::fromStored('9:16',720,1280)->outputWidth());
```
- [ ] RED/GREEN opções inválidas, ASS injection, CTA somente últimos5s, fonte/cor/fade/pop/bold reais, logo estrangeiro/referência ausente recusada. Logo FFmpeg extra input privado via storage, resolverowned; scale/overlay controlados no filter_complex com mapeamento explícito. Nunca concatenate rawusertext em filtergraph.
- [ ] Preservar transcrição em reexport: helper combina SRTvalidado com snapshot owned da versão original SOMENTE intervaloigual; cues intatos mantêm palavras/idioma; tokens1:1 corrigidos mantêm timestamps; alteredcue timing/token count removewords só daquelecue. Service snapshot leitura dentrolock e persistente clone; teste reapertura/reexport com karaoke mantendo tags porpalavra. Sem calls Gemini.
- [ ] Atualizar apenas expectativas720 deliberadamente alteradas para novos pedidos; mantertesteslegacy. Renderizar fixtureFFmpeg pequena nos três novos formatos, logo e overlays, ffprobe geometry+decode. Não tocar controller/view/editorJS (outro dono), AIprompt ou configsecrets.
- [ ] Relatório .superpowers/sdd/2026-09-06-media-homologation/task-1-report.md com RED/GREEN, arquivos, comandos e resultados.
