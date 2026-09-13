# Consistência obrigatória de legendas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Work directly in the canonical project folder as required by AGENTS.md.

**Goal:** Nenhuma nova exportação pode concluir sem transcrição sincronizada válida e legenda visível.

**Architecture:** Criar perfil e track na admissão, executar generate_subtitles antes de render_clip e validar novamente na fronteira de renderização. O editor reutiliza texto manual validado, sem substituir correções por nova chamada de IA.

**Tech Stack:** PHP 8, PDO, fila existente, Gemini, FFmpeg/ASS, JavaScript, PHPUnit/SQLite e Playwright.

**Spec:** Pedido do usuário desta tarefa, consolidado em “Política aceita” abaixo.

## Política aceita e restrições globais

- Todos os novos cortes passam pela etapa de legendas; modos auto e manual com texto sincronizado são aceitos.
- Modo none, estilo none e transcrição vazia não podem produzir nova exportação.
- Falhas de IA, áudio ou persistência não podem produzir sucesso silencioso.
- Preservar templates armazenados, transcrições corrigidas, limites precisos de duração, leases, cotas e arquivos já concluídos.
- Não inventar fala quando não há conteúdo transcrevível: indicar falha e permitir revisão no editor.
- Pasta canônica: C:\Users\Acer\Desktop\video. Nenhum novo worktree externo.
- Testes automatizados usam SQLite em memória e mídia temporária; não executar testes de schema no MariaDB compartilhado.
- Homologação real usa conteúdo do vídeo oficial 7YC9tf-qmmw, com novas versões e sem publicação externa.

## 1. Admissão e integridade — root

Arquivos: app/Services/ClipRenderRequestService.php, app/Repositories/ClipRepository.php, app/Repositories/ClipEditorRepository.php; tests/Unit/ClipRenderRequestServiceTest.php e ClipEditorRepositoryTest.php.

- [x] Reproduzir render_clip direto sem track em teste SQLite; verificar falha anterior à alteração.
- [x] Criar atomicamente perfil auto/minimal e track pending com a duração exata do corte; parent_clip_id nulo para sugestão original.
- [x] Despachar generate_subtitles com chave clip-subtitles:{id}:v{revision}; preservar replay e rollback.
- [x] Rejeitar áudio ausente antes da admissão automática e transcrição vazia antes da persistência ready.
- [x] Executar testes de admissão, integridade, scheduler e precisão de EOF.

Contrato entre tarefas:

```php
$editor->createProfile($clipId, $revision, null, $userId, $requestKey,
    EditorOptions::fromArray(['style' => 'minimal']), 'auto', $durationMs);
$jobs->dispatch('generate_subtitles', $projectId,
    ['clip_id' => $clipId, 'render_revision' => $revision],
    'clip-subtitles:'.$clipId.':v'.$revision);
```

## 2. Worker — audit_subtitle_worker

Arquivos: app/Queue/GenerateSubtitlesHandler.php, RenderClipHandler.php, ProcessingErrorCatalog.php e respectivos testes Unit.

- [x] Reproduzir snapshot ausente, modo none, ready vazio, pending manual, falha persistida e resposta da IA vazia.
- [x] Auto pending extrai áudio e transcreve; manual ready reutiliza Transcript sem alterar texto ou chamar IA.
- [x] Exigir modo auto/manual, opções visíveis, duração correspondente e pelo menos um cue; responder subtitle_empty para ausência de fala.
- [x] RenderClipHandler só chama renderer/publicação com track ready e Transcript válido não vazio; pending válido aguarda, inválido falha.
- [x] Preservar idempotência de jobs obsoletos, tentativas limitadas, leases, cleanup e cotas.
- [x] Executar regressões puras de worker, falhas e duração.

## 3. Editor — audit_subtitle_editor

Arquivos: app/Services/ClipEditService.php, app/Controllers/ClipEditorController.php, app/Views/clips/editor.php, public/assets/js/clip-editor.js, editor-library.js e testes associados.

- [x] Reproduzir mode none, style none, SRT vazio e default legado sem legenda.
- [x] Permitir apenas auto/manual com estilo visível; manual exige SRT não vazio com tempos válidos.
- [x] Despachar generate_subtitles também para manual ready, preservando TranscriptRevision e palavras sincronizadas.
- [x] Defaults novos/legados sem transcrição: auto + minimal. Transcrição existente: manual, preservada.
- [x] Remover opções sem legenda da nova exportação; normalizar apenas o draft de template legado none e informar o usuário.
- [x] Executar testes PHP/SQLite e fixtures de navegador, incluindo o fluxo de edição manual.

## 4. Verificação integrada e relatório — root + revisão independente

- [x] Testar o encadeamento real dos serviços/repositórios em SQLite: admissão → legendas ready → job render, ou falha sem render.
- [x] Revisar independentemente o diff das três frentes e corrigir achados concretos.
- [x] Criar novas versões reais a partir do vídeo oficial pelo localhost, verificar transcrição, MP4, duração, decodificação e frames com legendas.
- [x] Verificar edição manual sem nova transcrição automática e sem perder texto/timestamps.
- [x] Documentar resultados medidos e limitações, sem declarar os arquivos antigos regenerados automaticamente.
- [x] Manter localhost ativo em http://localhost:8093 e registrar evidências privadas em .local-history/subtitle-consistency-20260907.

Comandos focados (sem suíte de integração MySQL):

```powershell
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --filter 'ClipRenderRequestServiceTest|ClipEditorRepositoryTest|AutoRenderPrecisionTest|AutoRenderSchedulerTest' tests/Unit
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit tests/Unit/GenerateSubtitlesHandlerTest.php
& C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit tests/Unit/RenderClipHandlerTest.php
```

Não criar commits automáticos nem reconstruir o pacote de entrega antigo durante a correção; manter histórico e arquivos do usuário intactos.

## Encerramento verificado

As tarefas 1–4 foram executadas e revisadas. Resultado final e evidências: `docs/CORRECAO_CONSISTENCIA_LEGENDAS.md`. Verificação final: 209 testes PHP/942 asserções; teste JavaScript; MP4s reais 52 revisão 2, 66 e 67; downloads MP4/SRT; três rejeições HTTP 422. Mantidos os arquivos legados e localhost ativo. Nenhum deploy na Hostinger foi efetuado.
