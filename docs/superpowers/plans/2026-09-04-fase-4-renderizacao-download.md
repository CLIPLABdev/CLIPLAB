# Fase 4 Renderização e Download Privado Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que o proprietário aprove uma sugestão, ajuste o intervalo, gere MP4 e thumbnail reais em job assíncrono e baixe o resultado por uma rota privada.

**Architecture:** Um serviço transacional registra a solicitação e despacha `render_clip`. O handler usa um `ClipRenderer` substituível; o adaptador local executa FFmpeg por `ProcessRunner`, publica artefatos no `PrivateStorage` e persiste somente chaves relativas. Controllers autenticados oferecem status público e emissão em chunks após conferir ownership.

**Tech Stack:** PHP 8.0+, MySQL 8, PDO, FFmpeg/FFprobe, cron com fila MySQL, HTML/CSS, JavaScript puro e PHPUnit 9.

**Spec:** `docs/superpowers/specs/2026-09-04-fase-4-renderizacao-download-design.md`

## Global Constraints

- Produção precisa funcionar sem Node.js, Docker, Redis, WebSocket ou processo permanente.
- Nenhuma requisição web pode executar FFmpeg.
- Origem, MP4 e thumbnail permanecem fora de `public/`.
- Nenhum caminho absoluto, object key, stderr, payload de job ou segredo aparece em resposta pública.
- Todo POST usa sessão autenticada e CSRF.
- Toda leitura usa `clip.id + projects.user_id`; inválido, inexistente, estrangeiro e análise antiga são indistinguíveis.
- Intervalos obedecem `start >= 0`, `end > start`, duração de 1 a 180 segundos e fim dentro da mídia.
- O argv FFmpeg é uma lista fixa; nenhum argumento de shell vem diretamente do request.
- MP4 inicial preserva proporção e usa H.264/AAC; thumbnail é JPEG com largura máxima de 640 px.
- `QUEUE_LEASE_SECONDS >= max(GEMINI_HTTP_TIMEOUT_SECONDS, RENDER_TIMEOUT_SECONDS) + 30`.
- A renderização não cria nem altera lançamentos no ledger de créditos.
- Cada tarefa começa com teste falhando, termina verde e recebe commit próprio.

---

### Task 1: Migration e persistência do ciclo de renderização

**Files:**
- Create: `database/migrations/202609040001_extend_clips_for_rendering.sql`
- Modify: `app/Repositories/ClipRepository.php`
- Modify: `app/Repositories/ProjectRepository.php`
- Create: `tests/Integration/ClipRenderRepositoryTest.php`
- Modify: `tests/Integration/AiAnalysisMigrationTest.php`

**Interfaces:**
- Consumes: `DatabaseJobDispatcher::dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int`.
- Produces: `ClipRepository::findForRenderRequest(int $clipId, int $userId, bool $forUpdate = false): ?array`, shaped as `{id:int,project_id:int,source_id:int,status:string,start_time:float,end_time:float,source_duration_seconds:int,render_start_time:?float,render_end_time:?float,render_revision:int}`.
- Produces: `ClipRepository::queueRender(int $clipId, float $start, float $end, int $revision): void`.
- Produces: `ClipRepository::findForRenderJob(int $clipId, int $revision): ?array`, shaped as `{id:int,project_id:int,status:string,render_revision:int,render_start_time:float,render_end_time:float,source:ProjectSource}`.
- Produces: `markRendering`, `markRenderQueued`, `markRenderFailed` and `completeRender`, all guarded by clip ID and revision.
- Produces: `ClipRepository::statusForOwnedClip(int $clipId, int $userId): ?array` and `artifactForOwnedClip(int $clipId, int $userId, string $kind): ?array`; the artifact shape is `{object_key:string,size_bytes:int,mime_type:string}`.
- Produces: `ProjectRepository::synchronizeRenderState(int $projectId): void`.

- [ ] **Step 1: Write failing migration assertions**

```php
self::assertTrue($this->hasColumn('clips', 'render_start_time'));
self::assertTrue($this->hasColumn('clips', 'render_end_time'));
self::assertTrue($this->hasColumn('clips', 'render_revision'));
self::assertTrue($this->hasColumn('clips', 'render_error_code'));
self::assertTrue($this->hasColumn('clips', 'output_size_bytes'));
self::assertTrue($this->hasColumn('clips', 'thumbnail_size_bytes'));
self::assertTrue($this->hasColumn('clips', 'approved_at'));
self::assertTrue($this->hasColumn('clips', 'render_requested_at'));
self::assertTrue($this->hasColumn('clips', 'rendered_at'));
```

- [ ] **Step 2: Run the migration test and confirm RED**

Run with `TEST_DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=cliplab;charset=utf8mb4`, user `root`, empty password:

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\AiAnalysisMigrationTest.php

Expected: FAIL because `render_start_time` does not exist.

- [ ] **Step 3: Add the additive migration**

```sql
ALTER TABLE clips
    ADD COLUMN render_start_time DECIMAL(10,3) UNSIGNED NULL AFTER end_time,
    ADD COLUMN render_end_time DECIMAL(10,3) UNSIGNED NULL AFTER render_start_time,
    ADD COLUMN render_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER render_end_time,
    ADD COLUMN render_error_code VARCHAR(64) NULL AFTER render_revision,
    ADD COLUMN output_size_bytes BIGINT UNSIGNED NULL AFTER output_file,
    ADD COLUMN thumbnail_size_bytes BIGINT UNSIGNED NULL AFTER thumbnail,
    ADD COLUMN approved_at DATETIME NULL AFTER thumbnail_size_bytes,
    ADD COLUMN render_requested_at DATETIME NULL AFTER approved_at,
    ADD COLUMN rendered_at DATETIME NULL AFTER render_requested_at;
```

- [ ] **Step 4: Write repository tests**

```php
$row = $clips->findForRenderRequest($clipId, $ownerId, true);
self::assertSame('suggested', $row['status']);
self::assertSame($sourceId, $row['source_id']);
self::assertNull($clips->findForRenderRequest($clipId, $otherUserId));

$clips->queueRender($clipId, 12.5, 37.25, 1);
$clips->markRendering($clipId, 1);
$clips->completeRender(
    $clipId,
    1,
    new StoredObject('processed/1/video.mp4', 1234, str_repeat('a', 64)),
    new StoredObject('thumbnails/1/thumb.jpg', 321, str_repeat('b', 64))
);
self::assertSame('completed', $clips->statusForOwnedClip($clipId, $ownerId)['status']);
self::assertArrayNotHasKey('output_file', $clips->statusForOwnedClip($clipId, $ownerId));
```

- [ ] **Step 5: Implement exact repository projections**

`findForRenderRequest` joins project, current analysis and ready source. `statusForOwnedClip` returns only ID, project ID, status, chosen times, render error code and updated timestamp. `artifactForOwnedClip` accepts only `video`/`thumbnail`, requires `completed` and returns key, size and server-selected MIME after ownership.

`synchronizeRenderState` uses aggregate counts: active exists → `rendering/96`; otherwise completed exists → `completed/100`; otherwise `suggestions_ready/92`.

- [ ] **Step 6: Run repository tests**

    C:\xampp\php\php.exe bin\migrate.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\AiAnalysisMigrationTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\ClipRenderRepositoryTest.php

Expected: PASS; foreign ownership and stale revision affect zero rows.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/202609040001_extend_clips_for_rendering.sql app/Repositories/ClipRepository.php app/Repositories/ProjectRepository.php tests/Integration/AiAnalysisMigrationTest.php tests/Integration/ClipRenderRepositoryTest.php
git commit -m "feat: persist clip render lifecycle"
```

---

### Task 2: Contrato e adaptador FFmpeg

**Files:**
- Create: `app/Contracts/ClipRenderer.php`
- Create: `app/Media/RenderClipRequest.php`
- Create: `app/Media/RenderedClipArtifacts.php`
- Create: `app/Media/LocalFfmpegClipRenderer.php`
- Create: `app/Exceptions/ClipRenderException.php`
- Create: `tests/Unit/RenderClipRequestTest.php`
- Create: `tests/Unit/LocalFfmpegClipRendererTest.php`

**Interfaces:**
- Consumes: `PrivateStorage::absolutePath` and `ProcessRunner::run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult`.
- Produces: `ClipRenderer::render(RenderClipRequest $request): RenderedClipArtifacts`.
- Produces: `RenderClipRequest::__construct(ProjectSource $source, float $startTime, float $durationSeconds, string $jobToken)`.
- Produces: artifact getters and idempotent `RenderedClipArtifacts::cleanup(): void`.

- [ ] **Step 1: Write failing DTO validation tests**

```php
$request = new RenderClipRequest($source, 12.5, 24.75, str_repeat('a', 32));
self::assertSame(12.5, $request->startTime());
self::assertSame(24.75, $request->durationSeconds());

$this->expectException(InvalidArgumentException::class);
new RenderClipRequest($source, NAN, 24.0, 'bad');
```

Cover finite values, duration 1–180, token `^[a-f0-9]{32}$`, positive artifact sizes and repeated cleanup.

- [ ] **Step 2: Run and confirm RED**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\RenderClipRequestTest.php

Expected: FAIL because the DTOs do not exist.

- [ ] **Step 3: Add contract and immutable DTOs**

```php
interface ClipRenderer
{
    public function render(RenderClipRequest $request): RenderedClipArtifacts;
}
```

`cleanup()` unlinks only its two exact temporary files and tolerates already-absent files.

- [ ] **Step 4: Write failing FFmpeg argv tests**

Use a recording `ProcessRunner` and fake storage:

```php
$renderer->render(new RenderClipRequest($source, 12.5, 23.0, str_repeat('b', 32)));
self::assertContains('12.500', $runner->commands[0], true);
self::assertContains('0:a?', $runner->commands[0], true);
self::assertContains('libx264', $runner->commands[0], true);
self::assertContains('-frames:v', $runner->commands[1], true);
```

Cover nonzero exit, timeout, missing output, excessive output, invalid MIME and cleanup.

- [ ] **Step 5: Implement `LocalFfmpegClipRenderer`**

Constructor:

```php
public function __construct(
    PrivateStorage $storage,
    ProcessRunner $runner,
    string $ffmpegBinary,
    string $temporaryDirectory,
    int $totalTimeoutSeconds,
    int $processOutputLimitBytes,
    int $videoMaxBytes,
    int $thumbnailMaxBytes
)
```

Resolve source through storage, create unpredictable private temp filenames, run fixed array argv, share one deadline between MP4 and JPEG, validate MIME/size and return artifacts. Map only to `render_unavailable`, `render_timeout`, `render_failed` or `render_output_invalid`; exception text contains no stderr/path.

- [ ] **Step 6: Run renderer regressions**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\RenderClipRequestTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\LocalFfmpegClipRendererTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\ProcessRunnerTest.php

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Contracts/ClipRenderer.php app/Media/RenderClipRequest.php app/Media/RenderedClipArtifacts.php app/Media/LocalFfmpegClipRenderer.php app/Exceptions/ClipRenderException.php tests/Unit/RenderClipRequestTest.php tests/Unit/LocalFfmpegClipRendererTest.php
git commit -m "feat: add secure FFmpeg clip renderer"
```

---

### Task 3: Resposta de arquivo em chunks

**Files:**
- Modify: `app/Core/Response.php`
- Create: `app/Services/PrivateFileResponseFactory.php`
- Create: `tests/Unit/StreamedResponseTest.php`
- Create: `tests/Unit/PrivateFileResponseFactoryTest.php`

**Interfaces:**
- Produces: `Response::stream(callable $emitter, int $status = 200): Response`.
- Produces: `PrivateFileResponseFactory::download(string $absolutePath, string $downloadName, int $expectedSize): Response`.
- Produces: `PrivateFileResponseFactory::thumbnail(string $absolutePath, int $expectedSize): Response`.

- [ ] **Step 1: Write failing stream tests**

```php
$response = Response::stream(static function (): void {
    echo 'part-1';
    echo 'part-2';
})->withHeader('Content-Type', 'application/octet-stream');
ob_start();
$response->send();
self::assertSame('part-1part-2', (string) ob_get_clean());
self::assertSame('', $response->body());
```

Assert emitter invoked once and HTML/JSON responses remain unchanged.

- [ ] **Step 2: Run and confirm RED**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\StreamedResponseTest.php

Expected: FAIL because `Response::stream` does not exist.

- [ ] **Step 3: Add optional emitter**

Store `?\Closure $emitter`. `send()` emits status/headers, invokes it once and returns; otherwise it echoes body. `body()` stays string and is empty for streams, so Router remains unchanged.

- [ ] **Step 4: Write and implement file factory tests**

Create a 2.5 MiB fixture. Require exact bytes, content length/type, `private, no-store`, `nosniff` and CR/LF-free disposition:

```php
$response = $factory->download($path, "corte-\r\nmalicioso.mp4", filesize($path));
self::assertSame('video/mp4', $response->header('Content-Type'));
self::assertSame((string) filesize($path), $response->header('Content-Length'));
self::assertStringNotContainsString("\r", (string) $response->header('Content-Disposition'));
```

Factory uses 1 MiB `fread` chunks and `finally fclose`. Missing/unreadable or expected-size mismatch throws generic `RuntimeException`.

- [ ] **Step 5: Run response regressions**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\StreamedResponseTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\PrivateFileResponseFactoryTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\RouterTest.php

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Core/Response.php app/Services/PrivateFileResponseFactory.php tests/Unit/StreamedResponseTest.php tests/Unit/PrivateFileResponseFactoryTest.php
git commit -m "feat: stream private artifacts safely"
```

---

### Task 4: Serviço transacional de aprovação

**Files:**
- Create: `app/Media/ClipRenderReceipt.php`
- Create: `app/Exceptions/ClipRenderValidationException.php`
- Create: `app/Services/ClipRenderRequestService.php`
- Create: `tests/Unit/ClipRenderRequestServiceTest.php`
- Create: `tests/Integration/ClipRenderRequestIntegrationTest.php`

**Interfaces:**
- Consumes: Task 1 repository methods and `JobDispatcher::dispatch`.
- Produces: `ClipRenderRequestService::request(int $clipId, int $userId, string $startTime, string $endTime): ?ClipRenderReceipt`.
- Produces receipt getters `clipId()`, `projectId()`, `revision()`, `created()`.
- Produces: `ClipRenderValidationException::errors(): array<string,string>`.

- [ ] **Step 1: Write failing service tests**

```php
$receipt = $service->request(7, 3, '12.500', '35.250');
self::assertSame(7, $receipt->clipId());
self::assertSame(1, $receipt->revision());
self::assertSame(['clip_id' => 7, 'render_revision' => 1], $dispatcher->payload);
self::assertSame('clip-render:7:v1', $dispatcher->idempotencyKey);
```

Reject NaN, exponent/comma, negative, equal boundaries, duration below 1/above 180 and end beyond source. Foreign/stale returns null. Queued/rendering returns existing receipt with `created=false`. Completed returns validation error.

- [ ] **Step 2: Run and confirm RED**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\ClipRenderRequestServiceTest.php

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement parsing and transaction**

Accept only this complete-string pattern:

```php
/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,3})?\z/D
```

Convert only after the complete-string match, then transact in this order: lock → validate → increment revision → update clip → dispatch exact payload/key → synchronize project → commit. Any exception rolls back.

- [ ] **Step 4: Add MySQL integration**

Use two PDO connections for duplicate submission and a throwing dispatcher for rollback. Assert exactly one revision/job and identical credit ledger count before/after.

- [ ] **Step 5: Run tests**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\ClipRenderRequestServiceTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\ClipRenderRequestIntegrationTest.php

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Media/ClipRenderReceipt.php app/Exceptions/ClipRenderValidationException.php app/Services/ClipRenderRequestService.php tests/Unit/ClipRenderRequestServiceTest.php tests/Integration/ClipRenderRequestIntegrationTest.php
git commit -m "feat: queue approved clip renders"
```

---

### Task 5: Handler e publicação dos artefatos

**Files:**
- Create: `app/Queue/RenderClipHandler.php`
- Modify: `app/Queue/ProcessingErrorCatalog.php`
- Create: `tests/Unit/RenderClipHandlerTest.php`
- Create: `tests/Integration/RenderClipLifecycleTest.php`

**Interfaces:**
- Consumes: Task 1 repositories, Task 2 `ClipRenderer`, `PrivateStorage::putStream/delete` and `ProcessingEffectGuard::apply`.
- Produces: `RenderClipHandler implements JobHandler::handle(ClaimedJob $job): JobOutcome`.

- [ ] **Step 1: Write failing payload/state tests**

```php
$job = new ClaimedJob(
    41, 'media', 'render_clip', $projectId,
    ['clip_id' => $clipId, 'render_revision' => 1],
    'worker-test', str_repeat('a', 64), 1, 3
);
self::assertSame('completed', $handler->handle($job)->status());
```

Reject extra/missing/string payload fields, project mismatch, stale revision, old analysis and unready source before renderer invocation.

- [ ] **Step 2: Run and confirm RED**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\RenderClipHandlerTest.php

Expected: FAIL because handler does not exist.

- [ ] **Step 3: Implement happy path**

Generate keys inside the handler:

```php
$nonce = bin2hex(random_bytes(16));
$videoKey = sprintf('processed/%d/%d-%s.mp4', $job->projectId(), $clipId, $nonce);
$thumbnailKey = sprintf('thumbnails/%d/%d-%s.jpg', $job->projectId(), $clipId, $nonce);
```

Render, open both temp files read-only, call `putStream` with configured caps, then use the lease guard for `completeRender` and project synchronization. If the guard rejects, delete both new objects. Always call artifact cleanup in `finally`.

- [ ] **Step 4: Implement outcomes**

- `render_unavailable`: mark queued under guard and return `JobOutcome::deferred(300)`.
- transient error with attempts remaining: mark queued and return `JobOutcome::retry`.
- exhausted error: mark failed, synchronize project and return `JobOutcome::failed`.
- lost lease: remove published objects and avoid a database state change.

Catalog messages:

```php
'render_timeout' => 'A renderização demorou mais que o esperado.',
'render_failed' => 'Não foi possível renderizar este corte.',
'render_output_invalid' => 'O vídeo gerado não passou pela validação.',
'render_storage_failed' => 'Não foi possível armazenar o vídeo gerado.',
```

- [ ] **Step 5: Add MySQL lifecycle coverage**

Use a fake renderer that writes real temporary byte fixtures. Assert stored objects/sizes, retry, defer, exhausted failure, lost lease, completed idempotency, cleanup and unchanged `credit_transactions`.

- [ ] **Step 6: Run tests**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\RenderClipHandlerTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\RenderClipLifecycleTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\QueueWorkerTest.php

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Queue/RenderClipHandler.php app/Queue/ProcessingErrorCatalog.php tests/Unit/RenderClipHandlerTest.php tests/Integration/RenderClipLifecycleTest.php
git commit -m "feat: process clip render jobs"
```

---

### Task 6: Rotas, status e arquivos privados

**Files:**
- Create: `app/Controllers/ClipRenderController.php`
- Create: `app/Controllers/ClipAssetController.php`
- Create: `app/Services/ClipStatusService.php`
- Modify: `app/Controllers/ProjectSuggestionController.php`
- Modify: `app/Services/ProjectStatusService.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/ClipRenderAccessTest.php`
- Create: `tests/Feature/ClipAssetAccessTest.php`
- Create: `tests/Feature/ClipRoutesIntegrationTest.php`
- Modify: `tests/Feature/ProjectStatusEndpointTest.php`

**Interfaces:**
- Consumes: Tasks 1, 3 and 4.
- Produces: `POST /clips/{id}/render`, `GET /api/clips/{id}/status`, `GET /clips/{id}/thumbnail`, `GET /clips/{id}/download`.
- Produces: `ClipStatusService::forOwnedClip(int $clipId, int $userId): ?array`.

- [ ] **Step 1: Write failing access tests**

Test unauthenticated behavior, owner success, foreign/malformed/missing 404 and invalid CSRF 419:

```php
$request = Request::fake('POST', "/clips/{$clipId}/render", [
    '_token' => Csrf::token(),
    'start_time' => '12.500',
    'end_time' => '35.250',
]);
$response = $router->dispatch($request);
self::assertSame(302, $response->status());
self::assertSame("/projetos/{$projectId}", $response->header('Location'));
```

- [ ] **Step 2: Run and confirm RED**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ClipRenderAccessTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ClipAssetAccessTest.php

Expected: FAIL for missing controllers/routes.

- [ ] **Step 3: Implement public clip status**

Return exactly:

```php
[
    'id' => $clipId,
    'status' => $status,
    'stage' => $stage,
    'message' => $message,
    'render_start_time' => $row['render_start_time'],
    'render_end_time' => $row['render_end_time'],
    'thumbnail_url' => $status === 'completed' ? "/clips/{$clipId}/thumbnail" : null,
    'download_url' => $status === 'completed' ? "/clips/{$clipId}/download" : null,
    'updated_at' => $row['updated_at'],
]
```

Use internal error code only for catalog copy; do not return it.

- [ ] **Step 4: Implement controllers and routes**

Parse IDs with `/^[1-9][0-9]{0,18}$/D`. Asset controller looks up ownership/status first, resolves storage key, checks expected size and delegates to the file response factory. All lookup failures return the same 404.

Project status exposes `suggestions_url` for `suggestions_ready`, `rendering` and `completed`. Detail shows suggestions whenever the latest AI analysis is completed.

- [ ] **Step 5: Run route suites**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ClipRenderAccessTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ClipAssetAccessTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ClipRoutesIntegrationTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ProjectStatusEndpointTest.php

Expected: PASS; response bodies contain no private key/path.

- [ ] **Step 6: Commit**

```bash
git add app/Controllers/ClipRenderController.php app/Controllers/ClipAssetController.php app/Services/ClipStatusService.php app/Controllers/ProjectSuggestionController.php app/Services/ProjectStatusService.php routes/web.php tests/Feature/ClipRenderAccessTest.php tests/Feature/ClipAssetAccessTest.php tests/Feature/ClipRoutesIntegrationTest.php tests/Feature/ProjectStatusEndpointTest.php
git commit -m "feat: expose private clip render routes"
```

---

### Task 7: Interface e polling por corte

**Files:**
- Modify: `app/Views/projects/show.php`
- Modify: `app/Views/projects/index.php`
- Modify: `app/Views/dashboard/index.php`
- Modify: `public/assets/css/projects.css`
- Create: `public/assets/js/clip-status.js`
- Create: `tests/Browser/clip-status.test.js`
- Modify: `tests/Feature/ProjectAiViewsTest.php`

**Interfaces:**
- Consumes: Task 6 payload.
- Produces DOM hooks `data-clip-card`, `data-clip-status-url`, `data-clip-status`, `data-clip-message`, `data-clip-thumbnail`, `data-clip-download`.

- [ ] **Step 1: Write failing view assertions**

```php
self::assertStringContainsString('Aprovar e renderizar', $html);
self::assertStringContainsString('name="start_time"', $html);
self::assertStringContainsString('name="end_time"', $html);
self::assertStringContainsString('name="_token"', $html);
self::assertStringContainsString('/assets/js/clip-status.js', $html);
self::assertStringContainsString('Baixar MP4', $completedHtml);
self::assertStringNotContainsString('output_file', $completedHtml);
```

- [ ] **Step 2: Run and confirm RED**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ProjectAiViewsTest.php

Expected: FAIL for missing controls.

- [ ] **Step 3: Implement progressive markup**

Suggested/failed cards receive POST+CSRF with defaults from chosen or AI times. Queued/rendering show real stage plus refresh. Completed shows protected thumbnail and download. Do not display invented render percentage. Library/dashboard links remain active for suggestions-ready, rendering and completed.

- [ ] **Step 4: Write browser tests**

```javascript
await runScenario({
  status: 'completed',
  thumbnail_url: '/clips/44/thumbnail',
  download_url: '/clips/44/download',
});
assert.equal(download.hidden, false);
assert.equal(download.href, '/clips/44/download');
assert.equal(maxInflight, 1);
```

Reject external/protocol-relative/javascript/other-ID links and links before completed. Five failures display recovery copy and stop polling.

- [ ] **Step 5: Implement JS and responsive CSS**

```javascript
const thumbnailPath = new RegExp(`^/clips/${clipId}/thumbnail$`);
const downloadPath = new RegExp(`^/clips/${clipId}/download$`);
const terminal = new Set(['suggested', 'completed', 'failed']);
const delays = [3000, 5000, 8000, 15000];
```

Never overlap fetches; abort on page hide; update `aria-live` only on stage change. At 320 px fields/buttons use one column and no-JS refresh remains.

- [ ] **Step 6: Run UI tests**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ProjectAiViewsTest.php
    node tests\Browser\clip-status.test.js public\assets\js\clip-status.js
    node tests\Browser\project-status-concurrency.test.js public\assets\js\project-status.js

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Views/projects/show.php app/Views/projects/index.php app/Views/dashboard/index.php public/assets/css/projects.css public/assets/js/clip-status.js tests/Browser/clip-status.test.js tests/Feature/ProjectAiViewsTest.php
git commit -m "feat: add clip rendering controls"
```

---

### Task 8: Worker e operação Hostinger/VPS

**Files:**
- Modify: `config/media.php`
- Modify: `.env.example`
- Modify: `bin/process-jobs.php`
- Modify: `bin/check-requirements.php`
- Modify: `README.md`
- Modify: `docs/HOSTINGER.md`
- Create: `tests/Feature/RenderWorkerCompositionTest.php`
- Create: `tests/Feature/FfmpegRequirementsTest.php`
- Modify: `tests/Unit/MediaConfigTest.php`

**Interfaces:**
- Consumes: Tasks 2 and 5.
- Produces media config keys `ffmpeg_binary`, `render_timeout_seconds`, `render_max_output_bytes`, `render_max_duration_seconds`, `render_thumbnail_max_bytes`.
- Produces the fifth worker handler `render_clip`.

- [ ] **Step 1: Write failing config/composition tests**

```php
self::assertSame('ffmpeg', $config['ffmpeg_binary']);
self::assertSame(240, $config['render_timeout_seconds']);
self::assertSame(524288000, $config['render_max_output_bytes']);
self::assertSame(180, $config['render_max_duration_seconds']);
self::assertSame(10485760, $config['render_thumbnail_max_bytes']);
self::assertStringContainsString("'render_clip' => new RenderClipHandler(", $workerSource);
```

Require zero provider/FFmpeg calls at bootstrap and early lease failure.

- [ ] **Step 2: Run and confirm RED**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\MediaConfigTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\RenderWorkerCompositionTest.php

Expected: FAIL for missing keys/handler.

- [ ] **Step 3: Compose config, renderer and handler**

Use one runner allowlist with configured FFprobe and FFmpeg. Enforce:

```php
$requiredLease = max($httpTimeoutSeconds, $renderTimeoutSeconds) + 30;
if ($leaseSeconds < $requiredLease) {
    throw new RuntimeException('Worker lease is shorter than the provider or render operation budget.');
}
```

- [ ] **Step 4: Extend checker and docs**

Checker reports FFprobe, FFmpeg, `proc_open`, private storage/temp and lease without executing binaries or printing configured paths. Missing FFmpeg is WARN because VPS can claim jobs; unsafe lease is FALHA. Docs include cron, shared storage, environment names, output sizing, private routes and rollback.

- [ ] **Step 5: Run operation tests**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit\MediaConfigTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\RenderWorkerCompositionTest.php
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\FfmpegRequirementsTest.php
    C:\xampp\php\php.exe bin\check-requirements.php

Expected: tests PASS; checker may WARN only for genuine local capacity.

- [ ] **Step 6: Commit**

```bash
git add config/media.php .env.example bin/process-jobs.php bin/check-requirements.php README.md docs/HOSTINGER.md tests/Unit/MediaConfigTest.php tests/Feature/RenderWorkerCompositionTest.php tests/Feature/FfmpegRequirementsTest.php
git commit -m "feat: compose render worker deployment"
```

---

### Task 9: FFmpeg real, fluxo completo e freeze

**Files:**
- Create: `tests/Integration/LocalFfmpegClipRendererTest.php`
- Create: `tests/Integration/RenderedClipWorkflowTest.php`
- Modify: design spec only if verification finds a factual mismatch.

**Interfaces:**
- Consumes: Tasks 1–8.
- Produces: end-to-end verified Phase 4 baseline.

- [ ] **Step 1: Write real FFmpeg integration**

Generate a deterministic 4-second 640×360 test source with `testsrc` plus `sine`, H.264/AAC. Render seconds 1–3; require nonempty MP4/JPEG and inspect codec/duration with FFprobe:

```php
self::assertSame('h264', $metadata['video_codec']);
self::assertEqualsWithDelta(2.0, $metadata['duration'], 0.35);
self::assertGreaterThan(0, filesize($artifacts->thumbnailPath()));
```

- [ ] **Step 2: Resolve actual local FFmpeg**

Run:

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\LocalFfmpegClipRendererTest.php

If absent, locate an existing executable. If none exists, request tool escalation to download/install a trusted build, set only local ignored `.env` paths, and never commit binaries or secrets. Before freeze this test must PASS, not skip.

- [ ] **Step 3: Add complete MySQL workflow test**

Seed owner/project/ready source/completed analysis/suggestion. Request render, claim job, run worker with artifact-producing fake renderer, call status/thumbnail/download routes, assert owner bytes and foreign 404. Require one job, completed clip, positive sizes and unchanged ledger count.

- [ ] **Step 4: Run the full suite**

    C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration
    C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature
    node tests\Browser\project-status-concurrency.test.js public\assets\js\project-status.js
    node tests\Browser\clip-status.test.js public\assets\js\clip-status.js

Set all three `TEST_DB_*` variables so DB tests execute. Expected: zero failure and zero DB-dependent skip.

- [ ] **Step 5: Run static/security checks**

Lint every changed PHP file, run `git diff --check`, count-only scan staged content for key patterns and confirm `.env`, private media, MP4/JPEG, logs and temp files remain ignored/unstaged.

- [ ] **Step 6: Execute authenticated localhost smoke**

At `http://127.0.0.1:8088/projetos/903`, submit one valid render, run the finite worker until terminal, verify card/thumbnail/download, verify unauthenticated denial and leave the demo page viewable.

- [ ] **Step 7: Request code review**

Use `superpowers:requesting-code-review`. Review ownership, idempotency, lease loss, cleanup, argv safety, memory, public projections, Hostinger instructions and responsive UI. Fix accepted findings and rerun affected tests.

- [ ] **Step 8: Commit tests/fixes**

```bash
git add tests/Integration/LocalFfmpegClipRendererTest.php tests/Integration/RenderedClipWorkflowTest.php
git commit -m "test: verify rendered clip workflow"
```

- [ ] **Step 9: Final verification**

Require clean `git status --short`. Record HEAD, test totals, checker output, localhost URL and demo credentials without printing API keys or private paths.
