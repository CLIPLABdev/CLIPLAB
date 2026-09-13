# Thumbnails e preparação de publicação — Implementation Plan
> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox syntax.
**Goal:** Capas reais configuráveis e pacoteseditoriais privados por plataforma.
**Architecture:** Studio porclipeconcluído, jobsFFmpeg limitados, tabelasaditivas, histórico/manualsemredesexternas.
**Tech Stack:** PHP8/MySQL, FFmpeg, CSS/JS locais.
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

### Task 1: Studio de thumbnails e preparação de publicação
**Files:** novos ThumbnailStudioController,PublicationPreparationController; ThumbnailRepository,PublicationPreparationRepository; ThumbnailStudioService,PublicationPreparationService; handlersGenerateThumbnailCandidatesHandler,RenderThumbnailDesignHandler; domínio/FFmpeg separados em app/Media/Thumbnails/; views clips/thumbnail-studio.php e clips/publication.php; CSSJSpróprios; routes/thumbnail-studio.php; migrations202609060050/0051; testesUnit/Feature/Integration. Rootincluirotas/worker/nav; não tocar editor/baseRenderer/.env.
**Interfaces:** jobtypes generate_thumbnail_candidates/render_thumbnail_design registráveisworker, usarJobHandler/EffectGuard reais. Sources somentecompleted MP4owned. Logo optionalresolver(assetId,projectId)=>?objectKey daLibraryRepo. Rootinjeta.
- [ ] RED autorização/routing/validação/idempotência: candidato/requestforeignnãoescreve; paths/args rejeitados; exactsamejob2exec não duplica assets.
```php
self::assertNull($repository->findOwned($thumbnailId,$otherUserId));
self::assertSame(5,count($generator->generate($boundedRequest)));
```
- [ ] Migração clip_thumbnail_sets(id,clip_id,user_id,render_revision,request_key,status,error_code,count,timestamps), clip_thumbnails(id,set_id,clip_id,user_id,revision,kind,index/time,baseid,requestkey,template/title/style,status,error,objectkey,size,mime,width,height,timestamps). Uniqueclip/revisionset, ownrequestkey, objectkey, candidateindex; FKsdeidtiposexistentes. Tabelasprep/eventaditivas comsameownerjoins. Nãoaplicarreal semroot.
- [ ] Gerar no máximo5candidatos distintos buscando múltiplos momentos reais (não firstframe); heurística de nitidez/contraste com descartarblack, semafirmarface/expressãoAI se nãoimplementado. OpçãoOriginal virtualusaassetlegado;nãoduplicarobjectkey. Usuáriopodeescolhertempo válido, custo ratebounded.
- [ ] 3templates clean/bold/split, title<=120 UTF8, fonte/color/size/position controlados; logoownedoptional. Renderdesign1280x720 JPEGdoMP4originalnooffset;assescape/textfile seguro, nunca drawtextraw. Tempfora depublic,randompaths,reservas/cleanup/lease antespromover,outputcaps.
- [ ] GET/POSTstudio+generate/save; polling private endpoint; authenticated inline/downloadvariante. PreservarformssemJS, feedbackpending/failed/ready persistente. UIselecionacandidato/template,textcontrols/realpreviews; nenhuma geraçãofake.
- [ ] Publicaçõeslocal: /clips/{id}/publicacao GET/POST, /publicacoes/{id}/status POST e /download GETmetadata. Platformenum youtube,youtube_shorts,instagram,tiktok,facebook. Title255,description5000,caption2200,cta500,hashtagslist<=30tokens<=100cada. Draft/ready/exported/marked_published/archived/versionoptimistic; eventosappend-onlycomsnapshotallowlist. ReadyexigeMP4completed e thumbnailownedreadyquandoinformada; archivedclip/stale source não podeexporbytes. marked_publishedlabeledmanual.NenhumPOSTrede/OAuth.
- [ ] PacoteJSON/TXTdownload comtitle/desc/caption/hashtags/CTA/linksprivadosválidos, históriopersistênciarefresh e vínculo da capa. Testconcurrentversionconflict,foreignthumbnotbound,statusbadreject,noJSsave, realFFmpeg5JPEGvariantes+decode e visualquicklook.
- [ ] Relatório task-1-report.md com RED/GREEN/limites, compositioninstructionsroot e scopefiles completo.
