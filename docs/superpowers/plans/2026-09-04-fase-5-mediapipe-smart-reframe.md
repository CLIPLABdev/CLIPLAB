# Fase 5 — MediaPipe e Smart Reframe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar preview privado, seleção de proporção, reenquadramento central/manual/automático com MediaPipe em Web Worker e render FFmpeg versionado, sem quebrar o fluxo original e mantendo implantação em hospedagem compartilhada Hostinger.

**Architecture:** O navegador faz apenas a detecção facial local e envia um plano canônico, pequeno e não confiável; o PHP valida, persiste o snapshot junto da nova `render_revision` e o worker PHP constrói o filtro FFmpeg exclusivamente com dados server-owned. A origem permanece privada por uma rota single-range e o modo manual funciona sem JavaScript; os assets MediaPipe são fixos, self-hosted e verificados por SHA-256.

**Tech Stack:** PHP 8.0+, PDO, MySQL 8.0.16+ ou MariaDB 10.4+ com CHECK habilitado, PHPUnit 9.6, FFmpeg/FFprobe, JavaScript ES modules, Web Worker, `@mediapipe/tasks-vision` 1.0.1, Playwright apenas em desenvolvimento, Apache `.htaccess`/PHP built-in server.

**Spec:** `docs/superpowers/specs/2026-09-04-fase-5-mediapipe-smart-reframe-design.md`

## Global Constraints

- Produção continua em PHP 8.0+ e exige MySQL 8.0.16+ ou MariaDB 10.4+ com CHECK habilitado; o SQL e a introspecção devem passar nos dois engines suportados. Node, pnpm e Playwright são exclusivamente de desenvolvimento.
- Todo teste que altera schema/fila usa exclusivamente `cliplab_phase5_test`, recusa DSN cujo `dbname` não termine em `_test` e nunca toca o banco da demonstração em 8093.
- `@mediapipe/tasks-vision` deve ser exatamente `1.0.1`; modelo exatamente `blaze_face_short_range/float16/1`; nenhum `latest`, CDN ou download em runtime.
- Assets MediaPipe devem sair de `public/assets/vendor/mediapipe-tasks-vision-1.0.1/`, com licença, origem, tamanho e SHA-256 verificáveis offline.
- Formatos aceitos: `original`, `9:16` 720x1280, `1:1` 720x720, `16:9` 1280x720 e `4:5` 720x900.
- Modos aceitos: `original`, `center`, `manual`, `auto`; largura, altura, detector, filtro, codec, comando e path nunca vêm do cliente.
- `REFRAME_MAX_DURATION_SECONDS` tem padrão 90 e intervalo 1..180; `REFRAME_MAX_KEYFRAMES` suportado é exatamente 32; preview usa no máximo 180 frames, passo mínimo 500 ms e maior lado 320 px.
- Auto contém 2..32 keyframes, começa em 0, termina na duração do corte e usa tempos estritamente crescentes; manual contém exatamente um ponto em 0.
- Coordenadas são finitas, inclusivas em 0..1 e canônicas com no máximo seis casas; JSON automático tem no máximo 16 KiB e somente `at_ms`, `center_x`, `center_y`.
- Payload do job permanece exatamente `{clip_id, render_revision}`; perfil e keyframes nunca entram na fila.
- Job sem qualquer perfil para o clip é legado/original; perfil ausente apenas para a revisão reivindicada é inconsistência e nunca recebe fallback stale.
- Nenhum frame, bitmap, detecção bruta, embedding, identidade facial, object key, detector version ou keyframe aparece em logs/status público.
- O SDK/modelo só carrega depois do consentimento ativo `mediapipe_metrics` versão `2026-09-04`; revogação e concessão são idempotentes e protegidas pelo CSRF global.
- CSP ganha `worker-src 'self'`; `blob:`, `unsafe-eval` e rede externa são proibidos. `'wasm-unsafe-eval'` só pode entrar se o gate Chromium provar necessidade e houver revisão explícita.
- `GET /clips/{id}/source-preview` aceita no máximo um range e retorna 404 indistinguível para foreign, stale, inexistente, MIME/storage inválido.
- O renderer conserva `ClipRenderer::render(RenderClipRequest): RenderedClipArtifacts`, argv em array, `bypass_shell`, allowlist, deadlines, fencing, cleanup e publicação privada.
- A fase não inclui legendas, editor completo, versões de clip completed, proxy, detector em VPS, S3, billing ou painel administrativo.
- Toda tarefa segue RED → GREEN → regressão → commit e recebe revisão de spec e qualidade antes da próxima tarefa dependente.
- O gate final usa banco real sem skip, FFmpeg real sem skip, browser real e deixa `http://127.0.0.1:8093/projetos/{id}` aberto com um exemplo funcional.

## File and Interface Map

- `app/Media/Reframe/*`: valores imutáveis, parser estrito e filtro FFmpeg; nenhum HTTP, SQL ou filesystem.
- `app/Contracts/ClipRenderProfileStore.php`: fronteira fakeável usada pelo handler.
- `app/Repositories/ClipRenderProfileRepository.php`: snapshot/re-hidratação por `clip_id + render_revision` no PDO compartilhado.
- `app/Repositories/UserConsentRepository.php` e `app/Services/MediaPipeConsentService.php`: persistência e política versionada de consentimento.
- `app/Http/SingleByteRange.php` e `app/Services/PrivateRangeResponseFactory.php`: parsing/streaming single-range sem alterar downloads existentes.
- `app/Controllers/ClipSourcePreviewController.php`: ownership, storage e 200/206/416 da origem.
- `app/Services/ClipRenderRequestService.php`: única transação que valida intervalo/plano, cria revisão/perfil e despacha job.
- `app/Queue/RenderClipHandler.php`: resolve snapshot exato antes de `markRendering` e entrega `ReframePlan` ao renderer.
- `public/assets/js/reframe-tracker.js`: tracking/smoothing/simplificação puro e determinístico.
- `public/assets/js/reframe-worker.js`: único lugar que inicializa MediaPipe e recebe somente bitmaps/timestamps.
- `public/assets/js/reframe-editor.js`: progressive enhancement do card, sampling, overlay, cancelamento e payload canônico.
- `tools/vendor-mediapipe.mjs`: importação online explícita e verificação offline dos bytes versionados.

---

### Task 1: Gate de assets MediaPipe self-hosted

**Files:**
- Create: `package.json`
- Create: `pnpm-lock.yaml`
- Create: `.npmrc`
- Modify: `.gitignore`
- Create: `tools/vendor-mediapipe.mjs`
- Create: `tools/run-phpunit-files.ps1`
- Create: `app/Media/MediaPipeAssetManifestVerifier.php`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/LICENSE`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/manifest.json`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/vision_bundle.mjs`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/models/blaze_face_short_range_float16.tflite`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/wasm/vision_wasm_internal.js`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/wasm/vision_wasm_internal.wasm`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/wasm/vision_wasm_nosimd_internal.js`
- Create: `public/assets/vendor/mediapipe-tasks-vision-1.0.1/wasm/vision_wasm_nosimd_internal.wasm`
- Create: `public/assets/js/reframe-worker.js`
- Create: `tests/Browser/support/local-php-server.mjs`
- Create: `tests/Fixtures/mediapipe-synthetic-face.png`
- Create: `tests/Fixtures/mediapipe-synthetic-face.PROVENANCE.md`
- Modify: `app/Middleware/SecurityHeadersMiddleware.php`
- Modify: `.htaccess`
- Modify: `public/.htaccess`
- Test: `tests/Feature/MediaPipeAssetManifestTest.php`
- Test: `tests/Feature/SecurityHeadersMediaPipeTest.php`
- Test: `tests/Browser/mediapipe-real.spec.mjs`

**Interfaces:**
- Consumes: pacote npm exato `@mediapipe/tasks-vision@1.0.1` e URL fixa `https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite`.
- Produces: `MediaPipeAssetManifestVerifier::verify(string $versionedAssetRoot): bool` e `MediaPipeAssetManifestVerifier::VERSION === '1.0.1'`.
- Produces: `node tools/vendor-mediapipe.mjs import` para aquisição explícita e `node tools/vendor-mediapipe.mjs verify` para verificação totalmente offline.
- Produces: `tools/run-phpunit-files.ps1 -PhpBin <php> -TestPath <path...>`, sem path default; executa cada arquivo PHPUnit separadamente, continua após falhas e devolve exit 1 se qualquer arquivo falhar.
- Produces: protocolo inicial do module worker `{type:'initialize',request_id}` e `{type:'detect',request_id,bitmap,timestamp_ms}`; Task 9 conserva essas mensagens e acrescenta tracking.
- Produces: `startLocalPhpServer({phpBin,port,root,env={}}): Promise<{baseUrl:string,stop():Promise<void>}>`, com spawn sem shell, ambiente explícito, readiness HTTP e teardown.

- [ ] **Step 1: Criar testes RED do manifesto e CSP**

Use um diretório temporário para provar arquivo ausente, extra, symlink/reparse point e hash alterado. No fixture válido, exija estas chaves e nunca compare um hash inventado. Escreva também o spec Playwright e o helper de servidor neste passo: o spec deve falhar inicialmente por worker/assets/fixture ausentes.

```php
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
self::assertSame('1.0.1', $manifest['package']['version']);
self::assertSame('blaze_face_short_range/float16/1', $manifest['model']['revision']);
self::assertTrue((new MediaPipeAssetManifestVerifier())->verify($root));
self::assertStringContainsString("worker-src 'self'", $response->header('Content-Security-Policy'));
self::assertStringNotContainsString("'unsafe-eval'", $response->header('Content-Security-Policy'));
self::assertStringNotContainsString('blob:', $response->header('Content-Security-Policy'));
```

Crie também o runner PowerShell abaixo para evitar o comportamento do PHPUnit deste workspace que ignora caminhos posicionais adicionais:

```powershell
param(
    [Parameter(Mandatory = $true)]
    [string] $PhpBin,
    [Parameter(Mandatory = $true, ValueFromRemainingArguments = $true)]
    [string[]] $TestPath
)
if ([string]::IsNullOrWhiteSpace($PhpBin) -or -not (Test-Path -LiteralPath $PhpBin -PathType Leaf)) { exit 2 }
$failed = $false
foreach ($path in $TestPath) {
    & $PhpBin 'vendor\bin\phpunit' $path
    if ($LASTEXITCODE -ne 0) { $failed = $true }
}
if ($failed) { exit 1 }
exit 0
```

- [ ] **Step 2: Executar os testes e confirmar RED**

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Feature\MediaPipeAssetManifestTest.php tests\Feature\SecurityHeadersMediaPipeTest.php
```

Expected: FAIL porque verifier, manifesto e `worker-src` ainda não existem.

- [ ] **Step 3: Fixar o toolchain de desenvolvimento**

Crie `package.json` com versões exatas e scripts sem download implícito:

```json
{
  "private": true,
  "scripts": {
    "mediapipe:import": "node tools/vendor-mediapipe.mjs import",
    "mediapipe:verify": "node tools/vendor-mediapipe.mjs verify",
    "test:mediapipe-real": "playwright test tests/Browser/mediapipe-real.spec.mjs"
  },
  "devDependencies": {
    "@mediapipe/tasks-vision": "1.0.1",
    "@playwright/test": "1.55.0"
  }
}
```

Use `.npmrc` com `ignore-scripts=true`, `save-exact=true`, acrescente somente `/node_modules/` ao `.gitignore` e rode `pnpm install --lockfile-only --ignore-scripts` para gerar `pnpm-lock.yaml`. O lock deve registrar a integridade npm `sha512-rvRE2FmAZ6ZxKSw7wq+e+jQDpN3t1B/tD2mJz9SmAzb1msoDkd4dMoE4wAh8Z30Um0PQwLiHr9QtomhmXk3aUQ==` para tasks-vision 1.0.1.

- [ ] **Step 4: Gerar fixture facial sintética sem pessoa real**

Use o skill imagegen com este prompt exato: “Photorealistic square test portrait of one fictional adult person, front-facing head and shoulders, neutral expression, even studio lighting, plain light-gray background, face fully visible, no accessories, no text, no watermark; this person must not resemble any real individual.” Salve o PNG em `tests/Fixtures/mediapipe-synthetic-face.png`. O arquivo de proveniência registra data, prompt, finalidade de teste e “AI-generated fictional person”; não contém segredo nem URL temporária. O Playwright deve exigir pelo menos uma detecção nessa imagem.

- [ ] **Step 5: Instalar dependências de teste e confirmar o RED no Chromium**

Run:

```powershell
pnpm install --frozen-lockfile --ignore-scripts
$env:TEST_PHP_BIN='C:\xampp\php\php.exe'
pnpm exec playwright test tests\Browser\mediapipe-real.spec.mjs
```

Expected: FAIL por worker/asset versionado ainda ausente, com o helper iniciando seu próprio PHP em 127.0.0.1:8094. O spec não consulta nem depende do processo ambiente em 8093.

- [ ] **Step 6: Implementar importador e verificador offline**

O comando `import` deve exigir a versão instalada exata, copiar apenas a allowlist, buscar somente a URL fixa do modelo, limitar resposta a 20 MiB, rejeitar redirect final fora de `storage.googleapis.com` e gravar o manifesto ordenado. O comando `verify` não usa `fetch`; ele recalcula tamanho/SHA-256, rejeita links/reparse points, traversal e qualquer arquivo extra:

```javascript
const ALLOWED = [
  'LICENSE', 'manifest.json', 'vision_bundle.mjs',
  'models/blaze_face_short_range_float16.tflite',
  'wasm/vision_wasm_internal.js', 'wasm/vision_wasm_internal.wasm',
  'wasm/vision_wasm_nosimd_internal.js', 'wasm/vision_wasm_nosimd_internal.wasm'
];
const PACKAGE_VERSION = '1.0.1';
const MODEL_URL = 'https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite';
```

O manifesto usa `{package:{name,version,source,integrity,license},model:{source,revision},files:[{path,size_bytes,sha256}]}`. `manifest.json` não inclui o próprio hash; a allowlist do verifier trata esse arquivo como metadado e exige hashes de todos os outros sete arquivos.

- [ ] **Step 7: Importar bytes oficiais e registrar o trust anchor**

Run:

```powershell
node tools\vendor-mediapipe.mjs import
node tools\vendor-mediapipe.mjs verify
```

Expected: `verify` termina com exit 0; nenhum arquivo aparece fora da allowlist e `node_modules` permanece ignorado. Se os nomes reais do pacote divergirem, o importador deve falhar e a allowlist deve ser corrigida contra o tarball 1.0.1, nunca contra `latest`.

- [ ] **Step 8: Implementar verifier PHP, CSP e tipos MIME**

O verifier PHP repete a allowlist e usa `hash_file('sha256', ...)` com `hash_equals`; ele retorna `false` sem lançar e nunca imprime paths/hashes. Acrescente `worker-src 'self'` à CSP existente, preservando as permissões Bootstrap/Lucide atuais. Em `.htaccess` e `public/.htaccess`, antes das regras de rewrite:

```apache
AddType application/wasm .wasm
AddType application/octet-stream .tflite
AddType application/javascript .mjs
```

- [ ] **Step 9: Criar gate de module Worker em Chromium real**

`reframe-worker.js` importa o bundle/modelo somente após `initialize`; `detect` exige `ImageBitmap` e timestamp inteiro monotônico, chama `detectForVideo` e responde apenas `{type:'detected',request_id,count}`, sem devolver boxes. O helper exige `TEST_PHP_BIN`, usa `spawn(...,{shell:false,windowsHide:true,env})`, porta 8094 neste spec, readiness por HTTP e teardown em `afterAll`. O teste Playwright usa `channel:'chrome'`, observa toda request, exige `new URL(request.url()).origin === baseUrl`, carrega a fixture sintética como Blob/ImageBitmap e exige count >=1.

```javascript
page.on('request', request => {
  const url = new URL(request.url());
  if (url.hostname !== '127.0.0.1') external.push(request.url());
});
expect(external).toEqual([]);
```

- [ ] **Step 10: Executar gate completo de assets**

Run:

```powershell
$env:TEST_PHP_BIN='C:\xampp\php\php.exe'
node tools\vendor-mediapipe.mjs verify
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Feature\MediaPipeAssetManifestTest.php tests\Feature\SecurityHeadersMediaPipeTest.php
pnpm exec playwright test tests\Browser\mediapipe-real.spec.mjs
```

Expected: PASS; bundle, `.mjs`, WASM e modelo respondem com MIME correto, worker inicializa same-origin, detecção ocorre fora da main thread e não há request externa. Não acrescente `'wasm-unsafe-eval'` sem uma falha CSP reproduzível registrada no relatório da tarefa.

- [ ] **Step 11: Commit**

```powershell
git add package.json pnpm-lock.yaml .npmrc .gitignore tools app/Media/MediaPipeAssetManifestVerifier.php public/assets/vendor/mediapipe-tasks-vision-1.0.1 public/assets/js/reframe-worker.js app/Middleware/SecurityHeadersMiddleware.php .htaccess public/.htaccess tests/Feature/MediaPipeAssetManifestTest.php tests/Feature/SecurityHeadersMediaPipeTest.php tests/Browser/mediapipe-real.spec.mjs tests/Browser/support/local-php-server.mjs tests/Fixtures/mediapipe-synthetic-face.png tests/Fixtures/mediapipe-synthetic-face.PROVENANCE.md
git commit -m "build: vendor pinned mediapipe assets"
```

---

### Task 2: Domínio e configuração de planos de reenquadramento

**Files:**
- Create: `app/Media/Reframe/AspectRatio.php`
- Create: `app/Media/Reframe/ReframeKeyframe.php`
- Create: `app/Media/Reframe/ReframePlan.php`
- Create: `app/Media/Reframe/ReframeSubmission.php`
- Create: `app/Media/Reframe/ReframePlanValidator.php`
- Create: `app/Media/Reframe/ReframePlanResolution.php`
- Modify: `config/media.php`
- Modify: `.env.example`
- Test: `tests/Unit/AspectRatioTest.php`
- Test: `tests/Unit/ReframeKeyframeTest.php`
- Test: `tests/Unit/ReframePlanValidatorTest.php`
- Modify: `tests/Unit/MediaConfigTest.php`

**Interfaces:**
- Consumes: somente strings não confiáveis e duração inteira em milissegundos.
- Produces: `AspectRatio::fromString(string): self`, `original(): self`, `value(): string`, `outputWidth(): ?int`, `outputHeight(): ?int`, `isOriginal(): bool`.
- Produces: `ReframeKeyframe::__construct(int $atMs, float $centerX, float $centerY, string $source)`, getters e `centerXDecimal()/centerYDecimal(): string` com seis casas.
- Produces: factories `ReframePlan::original()`, `center(AspectRatio)`, `manual(AspectRatio, ReframeKeyframe)`, `automatic(AspectRatio, string $detectorVersion, array $keyframes)` e getters imutáveis.
- Produces: `ReframeSubmission::__construct(string $aspectRatio, string $mode, string $focusX, string $focusY, string $keyframesJson, bool $hasServerOwnedFields=false)`, `hasServerOwnedFields(): bool` e `original(): self`.
- Produces: `ReframePlanValidator::__construct(int $maxDurationSeconds=90, int $maxKeyframes=32, string $detectorVersion='tasks-vision-1.0.1/blazeface-short-f16-r1')`.
- Produces: `ReframePlanValidator::validate(ReframeSubmission $submission, int $durationMs): ReframePlan`.
- Produces: `ReframePlanResolution::matched(ReframePlan)`, `legacy()`, `mismatch()`, `state(): string`, `plan(): ?ReframePlan`.

- [ ] **Step 1: Escrever testes RED dos value objects**

Cubra o mapa fechado e rejeite aliases/case/whitespace. Prove que keyframe armazena milionésimos sem deriva:

```php
$ratio = AspectRatio::fromString('9:16');
self::assertSame(720, $ratio->outputWidth());
self::assertSame(1280, $ratio->outputHeight());
$point = new ReframeKeyframe(1250, 0.225, 1.0, 'detected');
self::assertSame('0.225000', $point->centerXDecimal());
self::assertSame('1.000000', $point->centerYDecimal());
```

- [ ] **Step 2: Escrever matriz RED do validator**

Inclua original, center, manual e auto válidos; rejeite JSON >16384 bytes, string numérica, expoente, `-0`, NaN/INF, sétima casa, chave extra, lista associativa, mais de 32, tempo repetido/fora de ordem/fora do corte, primeiro não zero, último diferente da duração, origem implícita inválida e qualquer reframe não original acima do limite configurado de 90 segundos.

```php
$submission = new ReframeSubmission(
    '4:5', 'auto', '', '',
    '[{"at_ms":0,"center_x":0.225,"center_y":0.5},{"at_ms":2000,"center_x":0.775,"center_y":0.5}]'
);
$plan = $validator->validate($submission, 2000);
self::assertSame('tasks-vision-1.0.1/blazeface-short-f16-r1', $plan->detectorVersion());
self::assertCount(2, $plan->keyframes());
```

- [ ] **Step 3: Executar testes e confirmar RED**

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\AspectRatioTest.php tests\Unit\ReframeKeyframeTest.php tests\Unit\ReframePlanValidatorTest.php tests\Unit\MediaConfigTest.php
```

Expected: FAIL porque classes e chaves de configuração ainda não existem.

- [ ] **Step 4: Implementar valores imutáveis e invariantes**

Use o mapa server-owned:

```php
private const OUTPUTS = [
    'original' => [null, null],
    '9:16' => [720, 1280],
    '1:1' => [720, 720],
    '16:9' => [1280, 720],
    '4:5' => [720, 900],
];
```

`ReframeKeyframe` aceita somente `manual|detected`, `atMs >= 0`, floats finitos 0..1 e guarda `(int) round($coordinate * 1000000)`. Factories de plano rejeitam original em modos não originais, keyframes em center/original, source diferente de `manual` no manual e diferente de `detected` no auto.

- [ ] **Step 5: Implementar parser lexical estrito**

Antes de `json_decode`, exija a gramática completa abaixo. Ela aceita somente whitespace JSON, array e objetos na ordem canônica `at_ms,center_x,center_y`; portanto não precisa remover strings, interpretar escapes ou procurar números por regex parcial:

```php
private const WS = '[\x20\x09\x0A\x0D]*';
private const INTEGER = '(?:0|[1-9][0-9]{0,8})';
private const COORDINATE = '(?:0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)';
private const DETECTOR_VERSION = 'tasks-vision-1.0.1/blazeface-short-f16-r1';

private function automaticJsonPattern(): string
{
    $ws = self::WS;
    $item = '\{' . $ws
        . '"at_ms"' . $ws . ':' . $ws . self::INTEGER . $ws . ','
        . $ws . '"center_x"' . $ws . ':' . $ws . self::COORDINATE . $ws . ','
        . $ws . '"center_y"' . $ws . ':' . $ws . self::COORDINATE
        . $ws . '\}';

    return '/\A' . $ws . '\[(?:' . $item . '(?:' . $ws . ',' . $ws . $item . ')*)?\]' . $ws . '\z/D';
}
```

Depois do match, use `json_decode(..., true, 8, JSON_THROW_ON_ERROR)`, exija lista com helper PHP 8.0-compatible `$value === [] || array_keys($value) === range(0, count($value) - 1)`, tipos nativos e exatamente três keys; não use `array_is_list()`. Testes adversariais incluem duplicate/reordered/escaped keys, strings com escapes, Unicode, expoente, leading/trailing bytes e whitespace fora do conjunto. Se `hasServerOwnedFields` for true, o validator recusa toda a submission. Original exige `aspect_ratio=original`, `mode=original`, focos vazios e JSON vazio/`[]`. Center exige proporção não original, focos vazios e sem keyframes. Manual exige proporção não original, focos canônicos e JSON vazio/`[]`, criando um ponto `manual` em 0. Auto exige focos vazios e JSON canônico, atribuindo `source=detected` e detector version no servidor.

- [ ] **Step 6: Adicionar configuração com limites fechados**

Em `config/media.php`, produza:

```php
'reframe_max_duration_seconds' => max(1, min(180, (int) Env::get('REFRAME_MAX_DURATION_SECONDS', '90'))),
'reframe_max_keyframes' => (int) Env::get('REFRAME_MAX_KEYFRAMES', '32'),
'reframe_preview_max_frames' => max(2, min(180, (int) Env::get('REFRAME_PREVIEW_MAX_FRAMES', '180'))),
'reframe_preview_max_edge' => max(64, min(320, (int) Env::get('REFRAME_PREVIEW_MAX_EDGE', '320'))),
'mediapipe_asset_version' => Env::get('MEDIAPIPE_ASSET_VERSION', '1.0.1'),
```

O checker da Task 10 recusará keyframes diferentes de 32 e versão diferente de 1.0.1. Registre os cinco nomes e defaults em `.env.example`, sem copiar valores de `.env`.

- [ ] **Step 7: Executar testes e regressão de config**

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\AspectRatioTest.php tests\Unit\ReframeKeyframeTest.php tests\Unit\ReframePlanValidatorTest.php tests\Unit\MediaConfigTest.php
```

Expected: PASS, incluindo 90/32/180/320/1.0.1, duração 1..180 e frames 2..180.

- [ ] **Step 8: Commit**

```powershell
git add app/Media/Reframe config/media.php .env.example tests/Unit/AspectRatioTest.php tests/Unit/ReframeKeyframeTest.php tests/Unit/ReframePlanValidatorTest.php tests/Unit/MediaConfigTest.php
git commit -m "feat: define smart reframe plans"
```

---

### Task 3: Schema e repositories de snapshots/consentimentos

**Files:**
- Create: `database/migrations/202609040004_create_clip_render_profiles.sql`
- Create: `database/migrations/202609040005_create_clip_reframe_keyframes.sql`
- Create: `database/migrations/202609040006_create_user_consents.sql`
- Create: `app/Contracts/ClipRenderProfileStore.php`
- Create: `app/Repositories/ClipRenderProfileRepository.php`
- Create: `app/Repositories/UserConsentRepository.php`
- Modify: `app/Core/Migrator.php`
- Test: `tests/Integration/ClipRenderProfileMigrationTest.php`
- Test: `tests/Integration/ReframeKeyframeMigrationTest.php`
- Test: `tests/Integration/UserConsentMigrationTest.php`
- Test: `tests/Integration/ClipRenderProfileRepositoryTest.php`
- Modify: `tests/Unit/MigratorTest.php`

**Interfaces:**
- Consumes: `ReframePlan`, `ReframeKeyframe`, `AspectRatio` e `ReframePlanResolution` da Task 2.
- Produces: `ClipRenderProfileStore::resolveForJob(int $clipId, int $renderRevision): ReframePlanResolution`.
- Produces: `ClipRenderProfileRepository::create(int $clipId, int $renderRevision, ReframePlan $plan): int`, `findForClipRevision(int,int): ?ReframePlan`, `hasForClip(int): bool` e `resolveForJob(int,int): ReframePlanResolution`.
- Produces: `UserConsentRepository::isActive(int $userId, string $purpose, string $policyVersion): bool`, `grant(int $userId, string $purpose, string $policyVersion, DateTimeImmutable $at): void` e `revoke(int $userId, string $purpose, string $policyVersion, DateTimeImmutable $at): void`.

- [ ] **Step 1: Escrever testes RED no banco SQL suportado para as três migrations**

Cada teste exige `TEST_DB_DSN` cujo `dbname` canônico termina em `_test` antes de abrir conexão ou executar DDL; DSN diferente falha, nunca vira skip. Confirme por `SELECT VERSION()` MySQL >=8.0.16 ou MariaDB >=10.4 e, em MariaDB, `@@check_constraint_checks = 1`. Rode o Migrator completo no setUp e crie um diretório temporário contendo somente uma cópia da migration-alvo. Apague apenas a linha-alvo do ledger, execute esse diretório, exija registro e confirme terceira execução vazia; não presuma que as outras duas migrations estarão em `$first`. Consulte `information_schema` para tipos, checks, índices, uniques e `DELETE_RULE='CASCADE'`. Um caso controlado pré-cria definição divergente, exige exceção antes do ledger e restaura a tabela correta em finally.

```php
$first = $migrator->run();
self::assertContains('202609040004_create_clip_render_profiles.sql', $first);
$pdo->exec("DELETE FROM migrations WHERE migration = '202609040004_create_clip_render_profiles.sql'");
self::assertContains('202609040004_create_clip_render_profiles.sql', $migrator->run());
self::assertSame([], $migrator->run());
```

- [ ] **Step 2: Executar migrations tests e confirmar RED**

Run:

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -u root -e "CREATE DATABASE IF NOT EXISTS cliplab_phase5_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Integration\ClipRenderProfileMigrationTest.php tests\Integration\ReframeKeyframeMigrationTest.php tests\Integration\UserConsentMigrationTest.php
```

Expected: FAIL porque as migrations ainda não existem.

- [ ] **Step 3: Criar uma tabela por migration**

Use exatamente um `CREATE TABLE IF NOT EXISTS` por arquivo para que DDL sobrevivente sem ledger seja recuperável:

```sql
CREATE TABLE IF NOT EXISTS clip_render_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clip_id BIGINT UNSIGNED NOT NULL,
    render_revision INT UNSIGNED NOT NULL,
    aspect_ratio ENUM('original', '9:16', '1:1', '16:9', '4:5') NOT NULL,
    reframe_mode ENUM('original', 'center', 'manual', 'auto') NOT NULL,
    output_width SMALLINT UNSIGNED NULL,
    output_height SMALLINT UNSIGNED NULL,
    detector_version VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clip_render_profiles_revision (clip_id, render_revision),
    KEY idx_clip_render_profiles_clip_created (clip_id, created_at),
    CONSTRAINT fk_clip_render_profiles_clip FOREIGN KEY (clip_id) REFERENCES clips (id) ON DELETE CASCADE,
    CONSTRAINT chk_clip_render_profiles_revision CHECK (render_revision > 0),
    CONSTRAINT chk_clip_render_profiles_shape CHECK (
        (aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL)
        OR (aspect_ratio = '9:16' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 1280)
        OR (aspect_ratio = '1:1' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 720)
        OR (aspect_ratio = '16:9' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 1280 AND output_height = 720)
        OR (aspect_ratio = '4:5' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 900)
    ),
    CONSTRAINT chk_clip_render_profiles_detector CHECK (
        (reframe_mode = 'auto' AND detector_version IS NOT NULL AND detector_version = 'tasks-vision-1.0.1/blazeface-short-f16-r1')
        OR (reframe_mode <> 'auto' AND detector_version IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE IF NOT EXISTS clip_reframe_keyframes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    render_profile_id BIGINT UNSIGNED NOT NULL,
    sequence_index TINYINT UNSIGNED NOT NULL,
    at_ms INT UNSIGNED NOT NULL,
    center_x DECIMAL(7,6) UNSIGNED NOT NULL,
    center_y DECIMAL(7,6) UNSIGNED NOT NULL,
    source ENUM('manual', 'detected') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clip_reframe_keyframes_sequence (render_profile_id, sequence_index),
    UNIQUE KEY uq_clip_reframe_keyframes_time (render_profile_id, at_ms),
    CONSTRAINT fk_clip_reframe_keyframes_profile FOREIGN KEY (render_profile_id) REFERENCES clip_render_profiles (id) ON DELETE CASCADE,
    CONSTRAINT chk_clip_reframe_keyframes_sequence CHECK (sequence_index <= 31),
    CONSTRAINT chk_clip_reframe_keyframes_time CHECK (at_ms <= 180000),
    CONSTRAINT chk_clip_reframe_keyframes_center_x CHECK (center_x <= 1),
    CONSTRAINT chk_clip_reframe_keyframes_center_y CHECK (center_y <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
CREATE TABLE IF NOT EXISTS user_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    policy_version VARCHAR(32) NOT NULL,
    granted_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_user_consents_version (user_id, purpose, policy_version),
    KEY idx_user_consents_active (user_id, purpose, policy_version, revoked_at),
    CONSTRAINT fk_user_consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_user_consents_dates CHECK (revoked_at IS NULL OR revoked_at >= granted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Implementar pós-condição estrutural antes do ledger**

Em `Migrator::run()`, chame `verifyKnownCreateTable($name)` depois de executeStatements e antes do INSERT em migrations. O método só trata os nomes 004/005/006; para cada um consulta `information_schema.COLUMNS`, `STATISTICS`, `TABLE_CONSTRAINTS`, `REFERENTIAL_CONSTRAINTS` e `CHECK_CONSTRAINTS` e exige ao menos as definições, índices, FKs e checks nomeados no SQL acima. Coluna ausente/tipo, unsigned, nullability ou length divergente; unique/FK/check ausente; e delete rule diferente lançam `RuntimeException('Migration schema postcondition failed.')` sem nome/path privado. Colunas/índices adicionados por migrations futuras são permitidos.

~~~php
$this->executeStatements((string) file_get_contents($file), $name);
$this->verifyKnownCreateTable($name);
$statement = $this->pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
~~~

O teste de definição divergente confirma que a linha não entrou no ledger. Testes unitários existentes confirmam que migrations não mapeadas continuam com o comportamento atual.

- [ ] **Step 5: Escrever repository tests RED**

Prove round-trip de todos os modos, ordem/casas dos keyframes, unique revision, FK/cascade e resolução em três estados:

```php
$profileId = $repository->create($clipId, 2, $autoPlan);
self::assertGreaterThan(0, $profileId);
self::assertEquals($autoPlan, $repository->findForClipRevision($clipId, 2));
self::assertSame('matched', $repository->resolveForJob($clipId, 2)->state());
self::assertSame('mismatch', $repository->resolveForJob($clipId, 3)->state());
self::assertSame('legacy', $repository->resolveForJob($legacyClipId, 1)->state());
```

Execute o novo teste antes da implementação:

```powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\ClipRenderProfileRepositoryTest.php
```

Expected: FAIL porque os repositories ainda não existem.

- [ ] **Step 6: Implementar repositories com prepared statements**

`create` não abre/fecha transação: ele usa o PDO recebido, insere perfil e depois keyframes com `sequence_index` 0..N-1. `findForClipRevision` faz duas queries preparadas, reidrata pelos factories e recusa linhas incoerentes. `resolveForJob` retorna exact match; sem exact usa `hasForClip` para distinguir mismatch de legacy.

Para consentimento, grant ativo é no-op; grant de linha revogada atualiza `granted_at=:at, revoked_at=NULL`; revoke atualiza somente `revoked_at IS NULL`. Valide IDs positivos e purpose/version contra `/^[a-z0-9_:-]{1,64}$/D` e `/^[0-9-]{1,32}$/D` antes do SQL.

- [ ] **Step 7: Executar migrations e repository tests**

Run:

```powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Integration\ClipRenderProfileMigrationTest.php tests\Integration\ReframeKeyframeMigrationTest.php tests\Integration\UserConsentMigrationTest.php tests\Integration\ClipRenderProfileRepositoryTest.php tests\Unit\MigratorTest.php
```

Expected: PASS; checks/uniques/FKs rejeitam violações e deleção do clip/user remove dependentes.

- [ ] **Step 8: Commit**

```powershell
git add database/migrations/202609040004_create_clip_render_profiles.sql database/migrations/202609040005_create_clip_reframe_keyframes.sql database/migrations/202609040006_create_user_consents.sql app/Contracts/ClipRenderProfileStore.php app/Repositories/ClipRenderProfileRepository.php app/Repositories/UserConsentRepository.php app/Core/Migrator.php tests/Integration/ClipRenderProfileMigrationTest.php tests/Integration/ReframeKeyframeMigrationTest.php tests/Integration/UserConsentMigrationTest.php tests/Integration/ClipRenderProfileRepositoryTest.php tests/Unit/MigratorTest.php
git commit -m "feat: persist versioned reframe profiles"
```

---

### Task 4: Preview privado com single byte range

**Files:**
- Create: `app/Http/UnsatisfiableByteRange.php`
- Create: `app/Http/SingleByteRange.php`
- Create: `app/Services/PrivateRangeResponseFactory.php`
- Create: `app/Controllers/ClipSourcePreviewController.php`
- Modify: `app/Repositories/ClipRepository.php`
- Modify: `routes/web.php`
- Test: `tests/Unit/SingleByteRangeTest.php`
- Test: `tests/Unit/PrivateRangeResponseFactoryTest.php`
- Test: `tests/Feature/ClipSourcePreviewAccessTest.php`
- Test: `tests/Feature/ClipSourcePreviewRangeTest.php`
- Modify: `tests/Feature/ClipRoutesIntegrationTest.php`

**Interfaces:**
- Consumes: `Request::header('Range')`, `Response::stream()`, `PrivateStorage::absolutePath()` e auth middleware já existente.
- Produces: `SingleByteRange::fromHeader(?string $header, int $totalBytes): ?self`, getters `start/end/length/totalBytes` e `contentRange(): string`.
- Produces: `PrivateRangeResponseFactory::__construct(?callable $openStream=null)`, com default `fopen($path, 'rb')` e seam somente para testes de corrida.
- Produces: `PrivateRangeResponseFactory::preview(string $absolutePath, int $expectedSize, string $contentType, ?string $rangeHeader): Response`.
- Produces: `ClipRepository::sourceForOwnedPreview(int $clipId, int $userId): ?array{storage_disk:string,object_key:string,size_bytes:int,mime_type:string}`.
- Produces: owner-only `GET /clips/{id}/source-preview` com 200/206/416.

- [ ] **Step 1: Escrever parser tests RED**

Cubra sem header, `bytes=0-9`, `bytes=90-`, `bytes=-10`, fim além do EOF e rejeite unidade errada, múltiplo, vazio, overflow, `-0`, início no/além do EOF, fim menor que início e total zero.

```php
$range = SingleByteRange::fromHeader('bytes=10-19', 100);
self::assertSame(10, $range->start());
self::assertSame(19, $range->end());
self::assertSame(10, $range->length());
self::assertSame('bytes 10-19/100', $range->contentRange());
```

- [ ] **Step 2: Escrever stream/access tests RED**

Crie fixture >2 MiB, capture `Response::send()` e exija bytes exatos, seek e headers. Feature tests cobrem guest redirect, owner, foreign, stale analysis, source não ready, MIME/disk/key/tamanho inválidos e storage exception; todos os erros detectáveis antes da construção da resposta são o mesmo 404. Acrescente corridas controladas entre `preview()` e `send()`: com opener fake prove que o emitter reutiliza o mesmo handle e não reabre pathname; truncate do handle produz corpo curto, sem mensagem/path e sem falso 404. Em sistemas que permitem rename/unlink de arquivo aberto, prove também que o handle transmite os bytes originais; no Windows não exija que essa operação de pathname seja aceita e não marque skip. O teste não deve esperar que uma resposta cujos headers já começaram possa ser convertida em outro status.

```php
$response = $factory->preview($path, filesize($path), 'video/mp4', 'bytes=1048570-1048580');
self::assertSame(206, $response->status());
self::assertSame('bytes 1048570-1048580/' . filesize($path), $response->header('Content-Range'));
self::assertSame('11', $response->header('Content-Length'));
```

- [ ] **Step 3: Executar testes e confirmar RED**

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\SingleByteRangeTest.php tests\Unit\PrivateRangeResponseFactoryTest.php tests\Feature\ClipSourcePreviewAccessTest.php tests\Feature\ClipSourcePreviewRangeTest.php
```

Expected: FAIL por classes, lookup e rota ausentes.

- [ ] **Step 4: Implementar parser overflow-safe**

Aceite somente `/\Abytes=(\d*)-(\d*)\z/D`, recuse vírgula/espaço/sinal, converta após verificar comprimento e round-trip `(string)(int)$raw === $raw` exceto zero canônico. `N-M` limita M ao EOF; `N-` termina no EOF; `-N` devolve os últimos `min(N,total)` bytes.

- [ ] **Step 5: Implementar streaming em chunks limitados**

Normalize somente `video/mp4`, `application/mp4 -> video/mp4`, `video/quicktime`, `video/webm`. Na preflight, antes de construir a `Response`, valide path absoluto e lível, abra uma única vez pelo opener, use `fstat` no handle, exija modo regular por `($stat['mode'] & 0170000) === 0100000`, tamanho igual ao esperado e `fseek($stream, $start, SEEK_SET) === 0`. Qualquer falha fecha o handle e pode ser mapeada a 404 pelo controller. O emitter captura somente esse handle já posicionado e então:

```php
$remaining = $length;
try {
    while ($remaining > 0) {
        $chunk = fread($stream, min(1048576, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
} finally {
    fclose($stream);
}
```

Em EOF precoce/truncamento depois da preflight, o emitter encerra o corpo curto silenciosamente, fecha em `finally`, não imprime diagnóstico/path e não tenta trocar o status depois dos headers; o `Content-Length` anunciado torna a resposta incompleta detectável pelo cliente. Rename/unlink do pathname não altera o handle capturado. Sempre envie `Accept-Ranges: bytes`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff` e `Content-Disposition: inline; filename="source-preview"`. Range inválido retorna body vazio, 416 e `Content-Range: bytes */{total}`.

- [ ] **Step 6: Implementar lookup, controller e composição**

O SQL junta clip → projeto owner → análise atual → source ready e exige size/MIME/object key. O controller valida ID com `/^[1-9][0-9]{0,18}$/D`, exige `storage_disk === 'local'`, resolve path absoluto e delega; qualquer exceção de lookup/storage/preflight da factory, antes de obter a `Response`, vira o mesmo 404. O emitter trata internamente o short-read depois de iniciada a emissão, por isso o controller nunca tenta remapeá-lo. Registre GET com `[$authenticated]`; não registre CSRF por rota porque o Router já o aplica globalmente aos POSTs.

- [ ] **Step 7: Executar testes focados e regressões de assets**

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\SingleByteRangeTest.php tests\Unit\PrivateRangeResponseFactoryTest.php tests\Feature\ClipSourcePreviewAccessTest.php tests\Feature\ClipSourcePreviewRangeTest.php tests\Feature\ClipRoutesIntegrationTest.php tests\Unit\PrivateFileResponseFactoryTest.php tests\Feature\ClipAssetAccessTest.php
```

Expected: PASS; download/thumbnail da Fase 4 permanecem integrais e inalterados.

- [ ] **Step 8: Commit**

```powershell
git add app/Http app/Services/PrivateRangeResponseFactory.php app/Controllers/ClipSourcePreviewController.php app/Repositories/ClipRepository.php routes/web.php tests/Unit/SingleByteRangeTest.php tests/Unit/PrivateRangeResponseFactoryTest.php tests/Feature/ClipSourcePreviewAccessTest.php tests/Feature/ClipSourcePreviewRangeTest.php tests/Feature/ClipRoutesIntegrationTest.php
git commit -m "feat: stream private source previews"
```

---

### Task 5: Snapshot transacional na solicitação de render

**Files:**
- Modify: `app/Core/Request.php`
- Modify: `app/Services/ClipRenderRequestService.php`
- Modify: `app/Controllers/ClipRenderController.php`
- Modify: `app/Controllers/ProjectSuggestionController.php`
- Modify: `routes/web.php`
- Modify: `tests/Unit/ClipRenderRequestServiceTest.php`
- Modify: `tests/Integration/ClipRenderRequestIntegrationTest.php`
- Modify: `tests/Integration/RenderClipLifecycleTest.php`
- Create: `tests/Integration/ClipReframeConcurrencyTest.php`
- Modify: `tests/Feature/ClipRenderAccessTest.php`
- Modify: `tests/Feature/ProjectSuggestionRouteIntegrationTest.php`

**Interfaces:**
- Consumes: `ReframeSubmission`, `ReframePlanValidator`, `ClipRenderProfileRepository` e o mesmo PDO usado por clips/projects/dispatcher.
- Produces: `Request::hasInput(string $key): bool` para distinguir campo proibido de ausência.
- Produces: `ClipRenderRequestService::__construct(PDO, ClipRepository, ProjectRepository, ClipRenderProfileRepository, ReframePlanValidator, JobDispatcher, int $maxDurationSeconds=180)`.
- Produces: `ClipRenderRequestService::request(int $clipId, int $userId, string $startTime, string $endTime, ?ReframeSubmission $reframe=null): ?ClipRenderReceipt`.
- Preserves: payload exato `['clip_id'=>$clipId,'render_revision'=>$revision]` e idempotency key `clip-render:{id}:v{revision}`.

- [ ] **Step 1: Escrever service tests RED**

Amplie o schema SQLite fake com profiles/keyframes e prove que original implícito continua compatível. Para nova revisão, exija ordem lock → validar → create profile → queue clip → dispatch → sync → commit e snapshot exato:

```php
$receipt = $service->request(7, 3, '12.500', '14.500', new ReframeSubmission('9:16', 'manual', '0.250000', '0.500000', ''));
self::assertSame(1, $receipt->revision());
self::assertSame(['clip_id' => 7, 'render_revision' => 1], $dispatcher->payload);
self::assertSame('manual', $profiles->findForClipRevision(7, 1)->mode());
```

Queued/rendering retorna receipt existente sem validar um plano novo; suggested/failed cria snapshot; completed continua recusado.

- [ ] **Step 2: Ampliar integração RED de rollback/concorrência**

Use o teste de banco real existente para dispatch exception, transação externa/savepoint, duas conexões, retry failed e ledger. Em qualquer falha exija zero perfil/keyframe/job e revisão/status anteriores; retry válido cria revisão seguinte e deixa ambos snapshots imutáveis.

```php
self::assertSame($ledgerBefore, $this->ledgerCount($pdo, $userId));
self::assertSame(1, $this->countJobs($pdo, $clipId, $revision));
self::assertSame(1, $this->countProfiles($pdo, $clipId, $revision));
```

- [ ] **Step 3: Executar testes e confirmar RED**

Run:

```powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\ClipRenderRequestServiceTest.php tests\Integration\ClipRenderRequestIntegrationTest.php tests\Integration\ClipReframeConcurrencyTest.php tests\Feature\ClipRenderAccessTest.php
```

Expected: FAIL porque serviço/controller ainda usam quatro argumentos e não persistem perfil.

- [ ] **Step 4: Implementar presença de input e adaptação do controller**

`Request::hasInput` usa `array_key_exists`. O controller lê somente strings `aspect_ratio`, `reframe_mode`, `focus_x`, `focus_y`, `reframe_keyframes`; default completo é `ReframeSubmission::original()`. Ele define `hasServerOwnedFields=true` se qualquer `detector_version`, `output_width`, `output_height`, `filtergraph` ou `ffmpeg_args` estiver presente. O serviço executa o lookup owner primeiro e converte toda recusa do validator em `ClipRenderValidationException(['reframe'=>'Configuração de enquadramento inválida.'], $clip['project_id'])`, sem revelar projeto foreign.

- [ ] **Step 5: Persistir tudo no mesmo escopo transacional**

Preserve begin/savepoint/rollback existentes. Depois de validar intervalo, calcule sem float acumulado `durationMs = (int) round(($end - $start) * 1000)`, valide plano, incremente revisão e execute exatamente:

```php
$this->profiles->create($clip['id'], $revision, $plan);
$this->clips->queueRender($clip['id'], $start, $end, $revision);
$this->jobs->dispatch('render_clip', $clip['project_id'], [
    'clip_id' => $clip['id'],
    'render_revision' => $revision,
], sprintf('clip-render:%d:v%d', $clip['id'], $revision));
$this->projects->synchronizeRenderState($clip['project_id']);
```

Use `reframe_max_duration_seconds` apenas no validator de plano; `render_max_duration_seconds` continua governando o intervalo original.

- [ ] **Step 6: Preservar somente old input permitido**

Flash/render old inclui `clip_id`, start/end, aspect/mode/focus e JSON somente quando string <=16384 bytes. `ProjectSuggestionController::renderErrors` permite `reframe`; `renderOld` normaliza valores contra allowlists antes da view. Nunca reflita detector, filtro, dimensions ou args.

- [ ] **Step 7: Compor dependências com um PDO único**

Atualize `$requestClipRender` em `routes/web.php` para cinco argumentos e instancie clips, projects, profiles, validator e dispatcher com o mesmo `$pdo`. Passe defaults de `config/media.php`; não abra nova conexão dentro de repository ou dispatcher.

- [ ] **Step 8: Executar testes focados e regressões da Fase 4**

Run:

```powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\ClipRenderRequestServiceTest.php tests\Integration\ClipRenderRequestIntegrationTest.php tests\Integration\ClipReframeConcurrencyTest.php tests\Integration\RenderClipLifecycleTest.php tests\Feature\ClipRenderAccessTest.php tests\Feature\ProjectSuggestionRouteIntegrationTest.php tests\Integration\ClipRenderRepositoryTest.php
```

Expected: PASS, job ainda possui somente duas chaves e ledger não muda.

- [ ] **Step 9: Commit**

```powershell
git add app/Core/Request.php app/Services/ClipRenderRequestService.php app/Controllers/ClipRenderController.php app/Controllers/ProjectSuggestionController.php routes/web.php tests/Unit/ClipRenderRequestServiceTest.php tests/Integration/ClipRenderRequestIntegrationTest.php tests/Integration/ClipReframeConcurrencyTest.php tests/Integration/RenderClipLifecycleTest.php tests/Feature/ClipRenderAccessTest.php tests/Feature/ProjectSuggestionRouteIntegrationTest.php
git commit -m "feat: snapshot reframe render requests"
```

---

### Task 6: Filtergraph server-owned e extensão retrocompatível do renderer

**Files:**
- Create: app/Media/Reframe/FfmpegReframeFilterBuilder.php
- Modify: app/Media/ProjectSource.php
- Modify: app/Media/RenderClipRequest.php
- Modify: app/Media/LocalFfmpegClipRenderer.php
- Test: tests/Unit/FfmpegReframeFilterBuilderTest.php
- Modify: tests/Unit/RenderClipRequestTest.php
- Modify: tests/Unit/LocalFfmpegClipRendererTest.php
- Create: tests/Integration/SmartReframeFfmpegTest.php

**Interfaces:**
- Consumes: ReframePlan e metadados width/height persistidos da origem.
- Produces: FfmpegReframeFilterBuilder::build(ReframePlan $plan, int $sourceWidth, int $sourceHeight): string.
- Produces: ProjectSource::__construct(int $id, int $projectId, string $storageDisk, string $objectKey, string $mimeType, ?int $width=null, ?int $height=null), width(): ?int, height(): ?int, hasUsableGeometry(): bool.
- Produces: RenderClipRequest::__construct(ProjectSource $source, float $startTime, float $durationSeconds, string $jobToken, ?ReframePlan $reframePlan=null) e reframePlan(): ReframePlan; quatro argumentos continuam significando original.
- Produces: LocalFfmpegClipRenderer::__construct(PrivateStorage $storage, ProcessRunner $runner, string $ffmpegBinary, string $temporaryDirectory, int $totalTimeoutSeconds, int $processOutputLimitBytes, int $videoMaxBytes, int $thumbnailMaxBytes, ?FfmpegReframeFilterBuilder $reframeFilters=null).
- Preserves: LocalFfmpegClipRenderer e ClipRenderer::render(); o builder entra como último argumento opcional do construtor e null cria imediatamente um FfmpegReframeFilterBuilder padrão, mantendo call-sites legados e reframe sempre disponível.

- [ ] **Step 1: Escrever geometry/filter tests RED**

Cubra origem landscape/portrait, todos os formatos, center/manual nas quatro bordas, paridade, clamp e dimensão mínima. Exija literal para 1920x1080 → 9:16 center:

~~~php
self::assertSame(
    'setpts=PTS-STARTPTS,crop=606:1080:657.000000:0.000000,scale=720:1280:flags=lanczos,setsar=1',
    $builder->build(ReframePlan::center(AspectRatio::fromString('9:16')), 1920, 1080)
);
~~~

Para auto, exija primeiro/último preservados, interpolação piecewise em t, números com seis casas, escaped commas e filtro com 32 pontos menor que 24576 bytes. Rejeite plano original, geometria <=0 e crop menor que 2 pixels. Escreva também SmartReframeFfmpegTest neste RED, gerando fonte determinística e cobrindo ao menos 9:16 center, 1:1 manual e 4:5 auto; o gate real deve falhar antes do builder.

- [ ] **Step 2: Escrever request/renderer tests RED**

Prove que construção antiga retorna plano original e argv antigo não ganha -vf. Para cada plano não original, capture argv e exija exatamente um par -vf/filter antes de codecs; geometria ausente/incompleta deve lançar ClipRenderException com código render_output_invalid. Thumbnail continua extraída do MP4 final.

~~~php
$request = new RenderClipRequest($source, 1.0, 2.0, str_repeat('a', 32), $manualPlan);
self::assertSame($manualPlan, $request->reframePlan());
self::assertSame(1, count(array_keys($argv, '-vf', true)));
~~~

- [ ] **Step 3: Executar testes e confirmar RED**

Run:

~~~powershell
$env:TEST_FFMPEG_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffmpeg.exe'
$env:TEST_FFPROBE_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffprobe.exe'
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\FfmpegReframeFilterBuilderTest.php tests\Unit\RenderClipRequestTest.php tests\Unit\LocalFfmpegClipRendererTest.php tests\Integration\SmartReframeFfmpegTest.php
~~~

Expected: FAIL porque builder, geometria e plano no request ainda não existem.

- [ ] **Step 4: Implementar geometria determinística**

Compare proporções por multiplicação cruzada de inteiros, derive candidate com floor e depois force crop par com 2 * intdiv((int) $candidate, 2). Para cada centro:

~~~php
$x = max(0.0, min($sourceWidth - $cropWidth, $centerX * $sourceWidth - $cropWidth / 2));
$y = max(0.0, min($sourceHeight - $cropHeight, $centerY * $sourceHeight - $cropHeight / 2));
~~~

Center usa 0.5/0.5; manual usa o único ponto. Auto pré-calcula x/y de cada keyframe e gera if(lt(t\,nextTime)\,linear\,tail) somente com tokens internos. Formate tempos/pixels via number_format($value, 6, '.', '') e recuse ponto-e-vírgula, colchetes e CR/LF mesmo que nenhuma entrada cliente entre no filtro.

- [ ] **Step 5: Estender ProjectSource e RenderClipRequest**

Width/height finais opcionais e independentes preservam os call-sites de ingest/Gemini e linhas legadas incompletas. Rejeite somente valor não-null <=0; `hasUsableGeometry()` só é true com ambos positivos. No request, normalize plano null para `ReframePlan::original()` e mantenha validações atuais de start/duration/token. O renderer exige `hasUsableGeometry()` apenas para plano não original e converte false em `ClipRenderException::withCode('render_output_invalid')`; original legado com geometria parcial continua válido.

- [ ] **Step 6: Integrar filtro ao argv sem shell**

No construtor, faça `$this->reframeFilters = $reframeFilters ?? new FfmpegReframeFilterBuilder();`. No renderer, exija source()->hasUsableGeometry() somente para plano não original e monte argv completo sem placeholder:

~~~php
$command = [
    $this->ffmpegBinary, '-nostdin', '-hide_banner', '-loglevel', 'error',
    '-ss', $this->formatSeconds($request->startTime()),
    '-i', $sourcePath,
    '-t', $this->formatSeconds($request->durationSeconds()),
    '-map', '0:v:0', '-map', '0:a?',
];
if (!$request->reframePlan()->isOriginal()) {
    $command[] = '-vf';
    $command[] = $this->reframeFilters->build(
        $request->reframePlan(),
        (int) $request->source()->width(),
        (int) $request->source()->height()
    );
}
array_push(
    $command,
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
    '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
    '-c:a', 'aac', '-b:a', '128k', $videoPath
);
$this->runProcess($command, $deadline);
~~~

Para original, não insira -vf e mantenha a sequência argv atual byte a byte. Não crie string de comando e não altere ProcessRunner, bypass_shell ou allowlist.

- [ ] **Step 7: Executar testes e regressões do renderer**

Run:

~~~powershell
$env:TEST_FFMPEG_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffmpeg.exe'
$env:TEST_FFPROBE_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffprobe.exe'
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\FfmpegReframeFilterBuilderTest.php tests\Unit\RenderClipRequestTest.php tests\Unit\LocalFfmpegClipRendererTest.php tests\Integration\LocalFfmpegClipRendererTest.php tests\Integration\SmartReframeFfmpegTest.php
~~~

Expected: PASS; integração real existente continua gerando original sem filtro.

- [ ] **Step 8: Commit**

~~~powershell
git add app/Media/Reframe/FfmpegReframeFilterBuilder.php app/Media/ProjectSource.php app/Media/RenderClipRequest.php app/Media/LocalFfmpegClipRenderer.php tests/Unit/FfmpegReframeFilterBuilderTest.php tests/Unit/RenderClipRequestTest.php tests/Unit/LocalFfmpegClipRendererTest.php tests/Integration/SmartReframeFfmpegTest.php
git commit -m "feat: build safe ffmpeg reframe filters"
~~~

---

### Task 7: Lifecycle do worker por revisão e status público

**Files:**
- Modify: app/Repositories/ClipRepository.php
- Modify: app/Queue/RenderClipHandler.php
- Modify: app/Services/ClipStatusService.php
- Modify: bin/process-jobs.php
- Modify: tests/Unit/RenderClipHandlerTest.php
- Modify: tests/Integration/RenderClipLifecycleTest.php
- Modify: tests/Integration/RenderedClipWorkflowTest.php
- Modify: tests/Feature/RenderWorkerCompositionTest.php
- Modify: tests/Feature/ProcessJobsCommandHardeningTest.php
- Modify: tests/Feature/ClipRoutesIntegrationTest.php

**Interfaces:**
- Consumes: ClipRenderProfileStore::resolveForJob(), ReframePlan, ProjectSource geometry e renderer da Task 6.
- Produces: RenderClipHandler::__construct(object $clips, object $projects, ClipRenderer $renderer, PrivateStorage $storage, ProcessingEffectGuard $effects, int $videoMaxBytes, int $thumbnailMaxBytes, RenderArtifactCleanupStore $cleanups, ClipRenderProfileStore $profiles, int $maxDurationSeconds=180).
- Produces: ClipRepository::findForRenderJob() com ProjectSource populado por width/height.
- Produces: status owner-safe com output_aspect_ratio e reframe_mode, nunca detector/keyframes.

- [ ] **Step 1: Escrever handler tests RED para matched/legacy/mismatch**

Atualize todos os helpers/call-sites do handler com store fake. Cubra erro de repository → deferred 15; matched entrega plano exato; nenhum perfil do clip → original; perfil de outra revisão → falha sanitizada sem renderer; reclaim da mesma revisão reutiliza snapshot.

~~~php
$profiles->willResolve(ReframePlanResolution::matched($manualPlan));
$outcome = $handler->handle($job);
self::assertSame('completed', $outcome->status());
self::assertSame($manualPlan, $renderer->lastRequest->reframePlan());
self::assertSame(['clip_id', 'render_revision'], array_keys($job->payload()));
~~~

- [ ] **Step 2: Ampliar lifecycle de banco real RED**

No fixture existente, teste matched original/manual/auto, legacy sem linha, mismatch persistente, retry/reclaim, lease loss antes/depois de publish, outbox e ledger. Para mismatch, exija render_output_invalid, renderer zero chamadas e nenhuma configuração de outra revisão.

- [ ] **Step 3: Executar testes e confirmar RED**

Run:

~~~powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\RenderClipHandlerTest.php tests\Integration\RenderClipLifecycleTest.php tests\Feature\RenderWorkerCompositionTest.php
~~~

Expected: FAIL porque handler não resolve profile e worker não o compõe.

- [ ] **Step 4: Resolver snapshot antes de markRendering**

Depois de validar payload/context e antes do primeiro effect guard:

~~~php
try {
    $resolution = $this->profiles->resolveForJob($payload['clip_id'], $payload['render_revision']);
} catch (Throwable) {
    return JobOutcome::deferred(self::LEASE_DEFER_SECONDS);
}
if ($resolution->state() === 'mismatch') {
    return $this->invalidProfileOutcome($job, $payload);
}
$plan = $resolution->state() === 'legacy'
    ? ReframePlan::original()
    : $resolution->plan();
~~~

invalidProfileOutcome usa ProcessingEffectGuard para markRenderFailed(..., 'render_output_invalid') + project sync; lease/DB failure vira deferred 15. Não passe por retry porque o snapshot ausente não se corrige repetindo o mesmo job.

- [ ] **Step 5: Popular geometria e entregar plano ao renderer**

Amplie findForRenderJob para selecionar project_sources.width,height e criar ProjectSource(..., $width, $height), preservando null para legado original. Construa RenderClipRequest com o plano resolvido; nenhuma outra etapa do publish/cleanup muda.

- [ ] **Step 6: Expor projeção pública mínima**

Faça statusForOwnedClip e listagem de sugestões associarem somente o profile da revisão atual. ClipStatusService retorna:

~~~php
'output_aspect_ratio' => $row['output_aspect_ratio'] ?? 'original',
'reframe_mode' => $row['reframe_mode'] ?? 'original',
~~~

Valide ambos contra allowlists e aplique fallback original; não retorne output dimensions, detector version, keyframes ou IDs de profile.

- [ ] **Step 7: Compor worker com repository no mesmo PDO**

Em bin/process-jobs.php, crie um ClipRenderProfileRepository($pdo), injete no handler e preserve uma instância do runner com allowlist FFprobe+FFmpeg. Atualize testes de source/composição; bootstrap e falha precoce de lease não podem iniciar processo, rede ou leitura de vídeo.

- [ ] **Step 8: Executar lifecycle/regressões**

Run:

~~~powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\RenderClipHandlerTest.php tests\Integration\RenderClipLifecycleTest.php tests\Integration\RenderedClipWorkflowTest.php tests\Feature\RenderWorkerCompositionTest.php tests\Feature\ProcessJobsCommandHardeningTest.php tests\Feature\ClipRoutesIntegrationTest.php tests\Unit\QueueWorkerTest.php
~~~

Expected: PASS; fencing, cleanup, outbox e payload permanecem sem regressão.

- [ ] **Step 9: Commit**

~~~powershell
git add app/Repositories/ClipRepository.php app/Queue/RenderClipHandler.php app/Services/ClipStatusService.php bin/process-jobs.php tests/Unit/RenderClipHandlerTest.php tests/Integration/RenderClipLifecycleTest.php tests/Integration/RenderedClipWorkflowTest.php tests/Feature/RenderWorkerCompositionTest.php tests/Feature/ProcessJobsCommandHardeningTest.php tests/Feature/ClipRoutesIntegrationTest.php
git commit -m "feat: render versioned smart reframes"
~~~

---

### Task 8: Consentimento versionado e editor manual sem JavaScript

**Files:**
- Create: app/Services/MediaPipeConsentService.php
- Create: app/Controllers/MediaPipeConsentController.php
- Modify: app/Controllers/ProjectSuggestionController.php
- Modify: app/Repositories/ClipRepository.php
- Modify: routes/web.php
- Modify: app/Views/projects/show.php
- Modify: public/assets/css/projects.css
- Modify: public/assets/css/projects-nojs.css
- Test: tests/Unit/MediaPipeConsentServiceTest.php
- Test: tests/Feature/MediaPipeConsentRoutesTest.php
- Test: tests/Feature/ProjectReframeViewsTest.php
- Modify: tests/Feature/ProjectAiViewsTest.php
- Modify: tests/Feature/ProjectNoJsSubmissionTest.php
- Modify: tests/Feature/ProjectHiddenControlsCssTest.php

**Interfaces:**
- Consumes: UserConsentRepository, render form da Task 5, preview URL da Task 4 e status público da Task 7.
- Produces: MediaPipeConsentService::PURPOSE === 'mediapipe_metrics', POLICY_VERSION === '2026-09-04', hasActive(int): bool, grant(int): void, revoke(int): void.
- Produces: MediaPipeConsentService::__construct(UserConsentRepository $consents, ?callable $clock=null), onde o clock retorna DateTimeImmutable.
- Produces: MediaPipeConsentController::__construct(MediaPipeConsentService $consents, callable $ownedProject, ?ErrorHandler $errors=null).
- Produces: POST /privacidade/consentimentos/mediapipe e POST /privacidade/consentimentos/mediapipe/revogar.
- Produces: `ProjectSuggestionController::__construct(View $view, callable $project, callable $clips, ?callable $user=null, ?ErrorHandler $errors=null, ?callable $consent=null, array $reframeConfig=[])`; `$consent` é `callable(int $userId): bool` e `$reframeConfig` aceita somente `array{reframe_max_duration_seconds?:mixed,reframe_preview_max_frames?:mixed,reframe_preview_max_edge?:mixed}`. Os cinco argumentos antigos conservam exatamente a posição e o significado.
- Produces DOM hooks `data-reframe-editor`, `data-source-preview-url`, `data-consent-active`, `data-reframe-max-duration-ms`, `data-reframe-max-frames`, `data-reframe-max-edge`, `data-reframe-preview-open`, `data-reframe-preview`, `data-reframe-overlay`, `data-reframe-status`, `data-reframe-auto` e `data-reframe-keyframes`.

- [ ] **Step 1: Escrever consent tests RED**

Use clock injetável fixo e prove grant, grant repetido, revoke, revoke repetido, grant após revoke e isolamento por user/version. Feature tests exigem guest redirect, CSRF 419, sessão válida, return project owner-safe e nenhum carregamento do SDK no HTML sem consentimento.

~~~php
$service->grant($userId);
self::assertTrue($service->hasActive($userId));
$service->grant($userId);
self::assertSame(1, $this->consentRowCount($userId, 'mediapipe_metrics', '2026-09-04'));
~~~

- [ ] **Step 2: Escrever view/no-JS tests RED**

Para suggested/failed exija select de proporção, modos original/center/manual, inputs numéricos focus_x/focus_y, hidden reframe_keyframes, resumo, disclosure, aria-live, botão `type="button" data-reframe-preview-open` e URL privada apenas em data-* sem video src. Exija também os limites 90000/180/320 projetados em data attributes. Queued/rendering/completed exibe badge e não editor. Form sem JS envia manual válido e o backend persiste um keyframe.

~~~php
self::assertStringContainsString('name="aspect_ratio"', $html);
self::assertStringContainsString('name="reframe_mode"', $html);
self::assertStringContainsString('name="focus_x"', $html);
self::assertStringContainsString('data-reframe-preview-open', $html);
self::assertStringContainsString('data-source-preview-url="/clips/41/source-preview"', $html);
self::assertStringContainsString('data-reframe-max-duration-ms="90000"', $html);
self::assertStringContainsString('data-reframe-max-frames="180"', $html);
self::assertStringContainsString('data-reframe-max-edge="320"', $html);
self::assertStringNotContainsString('<video src="/clips/41/source-preview"', $html);
~~~

- [ ] **Step 3: Executar testes e confirmar RED**

Run:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\MediaPipeConsentServiceTest.php tests\Feature\MediaPipeConsentRoutesTest.php tests\Feature\ProjectReframeViewsTest.php tests\Feature\ProjectNoJsSubmissionTest.php
~~~

Expected: FAIL porque service, rotas e controles ainda não existem.

- [ ] **Step 4: Implementar política e rotas**

O service valida user positivo e usa DateTimeImmutable UTC do clock. Controller lê return_project_id como ID canônico, confirma ownership por callable fn(int $projectId, int $userId): bool baseada em ProjectRepository::detailForOwnedProject, executa grant/revoke e redireciona para /projetos/{id}; sem owner válido, redireciona /projetos. O Router global protege ambos POSTs com CSRF.

- [ ] **Step 5: Projetar consentimento e profile para a view**

Acrescente `$consent` e `$reframeConfig` somente depois do quinto parâmetro existente do `ProjectSuggestionController`. No construtor, normalize o callable ausente para false e sanitize a configuração para inteiros: duração 1..180 segundos convertida para 1000..180000 ms, frames 2..180 e edge 64..320, com defaults 90/180/320. Em `show()`, resolva consentimento uma única vez por usuário e passe à view os top-level `mediaPipeConsentActive: bool` e `reframeUiConfig: array{max_duration_ms:int,max_frames:int,max_edge:int}`. `publicClips` continua exclusivamente por clip e inclui somente aspect/mode sanitizados e `source_preview_url` gerada no servidor; nunca inclui consentimento, configuração global, path ou object key. `routes/web.php` cria service/repository com o PDO da request, lê somente as chaves reframe de `config/media.php` e passa estado/config ao controller. Testes constroem o controller com cinco argumentos antigos e com valores extremos para provar compatibilidade, chamada única do consent callable e clamp.

- [ ] **Step 6: Implementar HTML funcional sem JavaScript**

Dentro de cada card suggested/failed, mantenha o POST existente e seus start/end. Use labels persistentes, valores old sanitizados e:

~~~html
<select name="aspect_ratio" data-reframe-ratio>
  <option value="original">Original</option>
  <option value="9:16">Vertical 9:16 — 720 × 1280</option>
  <option value="1:1">Quadrado 1:1 — 720 × 720</option>
  <option value="16:9">Horizontal 16:9 — 1280 × 720</option>
  <option value="4:5">Retrato 4:5 — 720 × 900</option>
</select>
<select name="reframe_mode" data-reframe-mode>
  <option value="original">Original</option>
  <option value="center">Centralizado</option>
  <option value="manual">Foco manual</option>
  <option value="auto" disabled hidden data-reframe-auto-option>Automático</option>
</select>
<input name="focus_x" type="number" min="0" max="1" step="0.000001" value="">
<input name="focus_y" type="number" min="0" max="1" step="0.000001" value="">
<input name="reframe_keyframes" type="hidden" value="" data-reframe-keyframes>
<button type="button" data-reframe-preview-open>Abrir prévia</button>
<video controls preload="metadata" data-reframe-preview></video>
~~~

O container `data-reframe-editor` recebe `data-consent-active="0|1"`, a URL privada e os três limites numéricos já sanitizados. Auto nunca é selecionável sem trajetória: sua option nasce disabled+hidden e só é habilitada/selecionada pelo JS após validação dos keyframes; em cancelamento/falha volta a disabled+hidden. Ele é ativado por botão type=button somente com JS. O preview começa sem src, mas `Abrir prévia` é uma ação independente que funciona para center/manual sem consentimento e carrega somente a rota privada; formulário de consentimento descreve processamento no dispositivo e métricas técnicas antes da ação afirmativa.

- [ ] **Step 7: Implementar CSS responsivo/fallback**

Use overlay com aspect-ratio, max-width:100%, focus ring visível e controles com altura mínima 44 px. Em @media (max-width: 600px), uma coluna e CTA width 100%; em 320 px nenhum overflow. prefers-reduced-motion: reduce remove transições/animações. projects-nojs.css mantém controles manual/center e os inputs numéricos visíveis; esconde apenas canvas e botões que exigem JavaScript.

- [ ] **Step 8: Executar testes de consentimento/UI e regressões**

Run:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Unit\MediaPipeConsentServiceTest.php tests\Feature\MediaPipeConsentRoutesTest.php tests\Feature\ProjectReframeViewsTest.php tests\Feature\ProjectAiViewsTest.php tests\Feature\ProjectNoJsSubmissionTest.php tests\Feature\ProjectHiddenControlsCssTest.php tests\Feature\CspInlineStyleTest.php
~~~

Expected: PASS; manual/center/original funcionam sem script e consentimento não carrega assets.

- [ ] **Step 9: Commit**

~~~powershell
git add app/Services/MediaPipeConsentService.php app/Controllers/MediaPipeConsentController.php app/Controllers/ProjectSuggestionController.php app/Repositories/ClipRepository.php routes/web.php app/Views/projects/show.php public/assets/css/projects.css public/assets/css/projects-nojs.css tests/Unit/MediaPipeConsentServiceTest.php tests/Feature/MediaPipeConsentRoutesTest.php tests/Feature/ProjectReframeViewsTest.php tests/Feature/ProjectAiViewsTest.php tests/Feature/ProjectNoJsSubmissionTest.php tests/Feature/ProjectHiddenControlsCssTest.php
git commit -m "feat: add consented manual reframe editor"
~~~

---

### Task 9: Tracking automático, module worker e progressive enhancement

**Files:**
- Create: public/assets/js/reframe-tracker.js
- Modify: public/assets/js/reframe-worker.js
- Create: public/assets/js/reframe-editor.js
- Modify: app/Views/projects/show.php
- Modify: public/assets/css/projects.css
- Test: tests/Browser/reframe-tracker.test.mjs
- Test: tests/Browser/reframe-worker.test.mjs
- Test: tests/Browser/reframe-editor.test.mjs
- Modify: tests/Browser/mediapipe-real.spec.mjs
- Test: tests/Feature/ReframeProgressiveEnhancementTest.php

**Interfaces:**
- Consumes: consentimento ativo server-rendered, `data-source-preview-url`, os três limites server-rendered, inputs start/end e assets da Task 1.
- Produces: buildReframeKeyframes(samples, durationMs, options): list de objetos canônicos at_ms/center_x/center_y.
- Produces: worker messages initialize, start, frame, finish e cancel; nenhuma mensagem contém URL, cookie, CSRF, object key, path ou credencial.
- Produces: hidden reframe_keyframes JSON, troca controlada do modo para auto e fallback explícito para center/manual.

- [ ] **Step 1: Escrever tracker harness RED**

Use amostras determinísticas para rosto único, dois rostos cruzando, duas ausências, três ausências, baixa confiança e nenhum rosto. Exija associação por IoU+distância, seleção por persistência/confiança/área, EMA alpha 0.35, preservação do primeiro/último ponto, simplificação com tolerância 0.025 e máximo 32.

~~~javascript
const points = buildReframeKeyframes(samples, 2000, {
  maxMissing: 2,
  alpha: 0.35,
  tolerance: 0.025,
  maxPoints: 32
});
assert.equal(points[0].at_ms, 0);
assert.equal(points.at(-1).at_ms, 2000);
assert.ok(points.every(p => Number.isFinite(p.center_x) && p.center_x >= 0 && p.center_x <= 1));
~~~

- [ ] **Step 2: Escrever worker/editor harnesses RED**

Worker fake cobre init, timestamps monotônicos, uma inferência em voo, bitmap sempre fechado, invalid message, timeout, cancel e finish sem track. Editor fake cobre: antes de qualquer ação, zero rede; clique de preview manual sem consentimento solicita somente `source-preview` e nunca worker/vendor/modelo; clique auto sem consentimento continua com zero worker/vendor/modelo; codec/load/seek failure mantém fallback numérico manual; Worker/WASM/OffscreenCanvas ausente não quebra preview/manual; limites DOM extremos/inválidos usam defaults; sampling <=180 com passo >=500 ms, start/end preservados, visibility/pagehide e JSON canônico.

~~~javascript
assert.equal(maxInflight, 1);
assert.ok(sampleTimes.length <= 180);
assert.equal(sampleTimes[0], 0);
assert.equal(sampleTimes.at(-1), durationMs);
assert.equal(externalRequests.length, 0);
~~~

- [ ] **Step 3: Executar harnesses e confirmar RED**

Run:

~~~powershell
node tests\Browser\reframe-tracker.test.mjs public\assets\js\reframe-tracker.js
node tests\Browser\reframe-worker.test.mjs public\assets\js\reframe-worker.js
node tests\Browser\reframe-editor.test.mjs public\assets\js\reframe-editor.js
C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ReframeProgressiveEnhancementTest.php
~~~

Expected: FAIL porque tracker/editor e protocolo completo ainda não existem.

- [ ] **Step 4: Implementar tracker puro e limitado**

Normalize cada bounding box por width/height antes do tracking. Associe ao track vivo de maior IoU; em empate use menor distância euclidiana do centro. Track sobrevive no máximo a duas amostras faltantes. Score final usa persistência primeiro, depois confiança média, depois área média, com desempate pelo menor ID interno. EMA:

~~~javascript
smoothX = alpha * detectedX + (1 - alpha) * smoothX;
smoothY = alpha * detectedY + (1 - alpha) * smoothY;
~~~

Simplifique recursivamente pelo maior desvio perpendicular normalizado, preserve endpoints e, se ainda houver mais de 32, faça downsample uniforme mantendo 0/duração. Arredonde coordenadas a seis casas e nunca retorne source/detector.

- [ ] **Step 5: Implementar worker de produção**

Importe somente paths relativos same-origin fixos. initialize cria FaceDetector em VIDEO e não acontece no carregamento do arquivo. start valida duration 1000..180000 e zera estado; o editor lê `data-reframe-max-duration-ms`, `data-reframe-max-frames` e `data-reframe-max-edge` por parser inteiro estrito, aplica defaults 90000/180/320 e os tetos 180000/180/320. frame exige request ID, ImageBitmap transferido, timestamp inteiro crescente e dimensões limitadas pelo edge projetado, nunca acima de 320; roda detectForVideo, guarda somente boxes normalizados em memória, fecha bitmap em finally e responde ack. finish chama tracker, descarta detecções e retorna somente keyframes; cancel/close libera detector e estado.

- [ ] **Step 6: Implementar editor e overlay**

No clique `Abrir prévia`, atribua `video.src` à rota privada e inicialize apenas vídeo/overlay; esta função não lê consentimento, não constrói Worker e não importa vendor/modelo. No clique auto, confirme `data-consent-active=1` antes de construir Worker; se o preview ainda não estiver carregado, reutilize a mesma função de source-preview. Só depois do consentimento construa o Worker. Calcule com `maxFrames` já sanitizado em 2..180:

~~~javascript
const stepMs = Math.max(500, Math.ceil(durationMs / (maxFrames - 1)));
const times = [0];
for (let at = stepMs; at < durationMs; at += stepMs) times.push(at);
if (times.at(-1) !== durationMs) times.push(durationMs);
~~~

Para cada tempo, busque start + at/1000, desenhe em canvas reduzido para maior lado <=320, transfira ImageBitmap e espere ack antes do próximo frame. Valide resultado como lista/keys/tipos/limites/ordem/endpoints/max32 antes de JSON.stringify; somente então habilite a option auto e a selecione. Falha limpa hidden, desabilita/esconde auto e volta para manual/center com mensagem aria-live.

Overlay manual usa pointer e teclado: setas movem 0.01, Shift+seta 0.05, clamp 0..1; atualiza inputs com seis casas. Mudança de clip, visibility hidden, pagehide ou novo start/end aborta seek, fecha bitmap, envia cancel, termina worker e remove src.

- [ ] **Step 7: Carregar script apenas como enhancement**

Inclua reframe-editor.js com defer/module no fim da view, sem inline script. Sem consentimento, o arquivo pode carregar mas não pode importar/criar o worker ou acessar vendor/modelo. Estados usam texto carregando/analisando/pronto/indisponível; não anuncie cada frame em aria-live.

- [ ] **Step 8: Executar harnesses e gate Chromium**

Run:

~~~powershell
node tests\Browser\reframe-tracker.test.mjs public\assets\js\reframe-tracker.js
node tests\Browser\reframe-worker.test.mjs public\assets\js\reframe-worker.js
node tests\Browser\reframe-editor.test.mjs public\assets\js\reframe-editor.js
C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature\ReframeProgressiveEnhancementTest.php
$env:TEST_PHP_BIN='C:\xampp\php\php.exe'
pnpm exec playwright test tests\Browser\mediapipe-real.spec.mjs
~~~

Expected: os harnesses editor provam, em 320/768/1440, zero rede antes da ação, preview manual sem consentimento pedindo somente a URL source-preview fake e zero worker/vendor/modelo antes do auto consentido. `mediapipe-real.spec.mjs` não acessa rota privada nem depende de DB/login: prova somente module Worker, MediaPipe/detecção real, viewport e rede same-origin usando `startLocalPhpServer` em 127.0.0.1:8094 com `url.origin === baseUrl`. O preview privado autenticado real fica no E2E da Task 11.

- [ ] **Step 9: Executar regressões de polling/JS**

Run:

~~~powershell
node tests\Browser\clip-status.test.js public\assets\js\clip-status.js
node tests\Browser\project-status-concurrency.test.js public\assets\js\project-status.js
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Feature\ProjectProgressiveEnhancementTest.php tests\Feature\ProjectNoJsTest.php tests\Feature\CspInlineStyleTest.php
~~~

Expected: PASS; os três fluxos não sobrepõem requests e cancelam ao ocultar a página.

- [ ] **Step 10: Commit**

~~~powershell
git add public/assets/js/reframe-tracker.js public/assets/js/reframe-worker.js public/assets/js/reframe-editor.js app/Views/projects/show.php public/assets/css/projects.css tests/Browser/reframe-tracker.test.mjs tests/Browser/reframe-worker.test.mjs tests/Browser/reframe-editor.test.mjs tests/Browser/mediapipe-real.spec.mjs tests/Feature/ReframeProgressiveEnhancementTest.php
git commit -m "feat: track faces for automatic reframing"
~~~

---

### Task 10: Checker e runbook Hostinger

**Files:**
- Create: app/Queue/WorkerLeaseBudget.php
- Modify: bin/check-requirements.php
- Modify: bin/process-jobs.php
- Modify: bootstrap/app.php
- Modify: .env.example
- Modify: README.md
- Modify: docs/HOSTINGER.md
- Modify: .htaccess
- Modify: public/.htaccess
- Test: tests/Feature/MediaPipeRequirementsTest.php
- Test: tests/Unit/WorkerLeaseBudgetTest.php
- Test: tests/Feature/BootstrapEnvFileTest.php
- Modify: tests/Feature/ProcessJobsCommandHardeningTest.php
- Modify: tests/Feature/AiWorkerCompositionTest.php
- Modify: tests/Feature/HostingerMediaRequirementsTest.php
- Modify: tests/Feature/HostingerFallbackConfigTest.php
- Modify: tests/Feature/FfmpegRequirementsTest.php
- Modify: tests/Unit/MediaConfigTest.php

**Interfaces:**
- Consumes: MediaPipeAssetManifestVerifier, media config, CSP e MIME da Task 1.
- Produces: checker silencioso sobre paths/hashes/secrets, preservando o vocabulário existente `[OK]`, `[WARN]`, `[INFO]` e `[FALHA]`.
- Produces: `WorkerLeaseBudget::requiredSeconds(int $geminiHttp, int $render, int $download, int $process, int $margin=30): int`, usado tanto pelo checker quanto por `process-jobs.php`.
- Produces: `APP_ENV_FILE` retrocompatível: ausente carrega `.env` como hoje, path explícito carrega esse arquivo e string vazia desabilita arquivo de ambiente para testes isolados.
- Produces: artefato Hostinger sem node_modules, npm, processo permanente, Redis, Docker ou WebSocket.

- [ ] **Step 1: Escrever requirements tests RED**

Cubra manifesto íntegro/corrompido, version mismatch, keyframe limit !=32, reframe duration fora de 1..180, preview frames fora de 2..180, ausência de worker-src, MIME directives ausentes, engine fora de MySQL >=8.0.16/MariaDB >=10.4 ou CHECK desabilitado, e lease abaixo de `max(Gemini HTTP, render, download + probe process) + 30`. `WorkerLeaseBudgetTest` cobre limites/overflow e `ProcessJobsCommandHardeningTest`/`AiWorkerCompositionTest` exigem que o worker chame o mesmo helper. `BootstrapEnvFileTest` usa subprocessos para provar default, path explícito e vazio sem ler o `.env` real. Capture output e exija que não contenha root absoluto, object key, SHA-256 nem valor de segredo. Testes também fixam que avisos continuam usando `[WARN]`, nunca `[AVISO]`.

~~~php
self::assertStringContainsString('[OK]', $output);
self::assertStringNotContainsString($privateRoot, $output);
self::assertStringNotContainsString('sha256', strtolower($output));
~~~

- [ ] **Step 2: Executar testes e confirmar RED**

Run:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Feature\MediaPipeRequirementsTest.php tests\Unit\WorkerLeaseBudgetTest.php tests\Feature\BootstrapEnvFileTest.php tests\Feature\ProcessJobsCommandHardeningTest.php tests\Feature\AiWorkerCompositionTest.php tests\Feature\HostingerMediaRequirementsTest.php tests\Feature\HostingerFallbackConfigTest.php tests\Feature\FfmpegRequirementsTest.php
~~~

Expected: FAIL porque checker e documentação não conhecem Smart Reframe.

- [ ] **Step 3: Integrar checks sem executar provider/binário**

Carregue verifier offline contra a pasta versionada, compare MEDIAPIPE_ASSET_VERSION 1.0.1, REFRAME_MAX_KEYFRAMES 32, duration 1..180, frames 2..180, edge 64..320, CSP worker-src e texto das três AddType. Preserve checks existentes de storage/upload/FFmpeg/cron/lease. `WorkerLeaseBudget` calcula `max(geminiHttpTimeout, renderTimeout, downloadTimeout + processTimeout) + 30`, pois fetch-and-probe usa download e probe sequencialmente; checker e `process-jobs.php` usam essa classe e nenhum deles conserva fórmula própria. O checker consulta apenas versão/capacidade do PDO já configurado: aceita MySQL >=8.0.16; em MariaDB >=10.4 também exige `@@check_constraint_checks = 1`. Checker não inicia browser, Node, FFmpeg, Gemini ou rede.

Em `bootstrap/app.php`, leia `APP_ENV_FILE` diretamente de `getenv`: quando ausente, preserve `dirname(__DIR__) . '/.env'`; quando for string vazia, não chame `Env::load`; quando for path explícito, carregue apenas ele. Produção Hostinger sem essa variável permanece idêntica. O E2E usará vazio e preencherá todas as chaves necessárias no ambiente do processo.

- [ ] **Step 4: Documentar deploy/rollback Hostinger**

README e HOSTINGER.md devem registrar: MySQL >=8.0.16 ou MariaDB >=10.4 com CHECK ativo; backup → código/vendor/assets → Composer --no-dev → migrations 004/005/006 → checker → cron finito → smoke; MIME .mjs/.wasm/.tflite; public root e fallback root; consentimento/privacidade; modo manual sem JS/codec; limites; `APP_ENV_FILE` normalmente ausente em produção; assets pré-compilados; node_modules excluído; FFmpeg em VPS futuro exige mesmo banco e storage privado compartilhado; rollback para cron antes do código e nunca apagar profiles/media/ledger.

- [ ] **Step 5: Provar artefato de produção sem Node**

No teste, monte uma lista de deploy a partir de arquivos rastreados e exija vendor MediaPipe presente, node_modules/package manager cache ausentes e nenhuma URL CDN nos arquivos reframe:

~~~php
self::assertFileExists($root . '/public/assets/vendor/mediapipe-tasks-vision-1.0.1/manifest.json');
self::assertDirectoryDoesNotExist($artifact . '/node_modules');
self::assertStringNotContainsString('cdn.', $reframeSources);
~~~

- [ ] **Step 6: Executar checker e testes operacionais**

Run:

~~~powershell
node tools\vendor-mediapipe.mjs verify
powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-phpunit-files.ps1 -PhpBin C:\xampp\php\php.exe -TestPath tests\Feature\MediaPipeRequirementsTest.php tests\Unit\WorkerLeaseBudgetTest.php tests\Feature\BootstrapEnvFileTest.php tests\Feature\ProcessJobsCommandHardeningTest.php tests\Feature\AiWorkerCompositionTest.php tests\Feature\HostingerMediaRequirementsTest.php tests\Feature\HostingerFallbackConfigTest.php tests\Feature\FfmpegRequirementsTest.php tests\Unit\MediaConfigTest.php
C:\xampp\php\php.exe bin\check-requirements.php
~~~

Expected: tests PASS; checker pode emitir apenas avisos verdadeiros de capacidade local já documentados, nunca FALHA.

- [ ] **Step 7: Commit**

~~~powershell
git add app/Queue/WorkerLeaseBudget.php bin/check-requirements.php bin/process-jobs.php bootstrap/app.php .env.example README.md docs/HOSTINGER.md .htaccess public/.htaccess tests/Feature/MediaPipeRequirementsTest.php tests/Unit/WorkerLeaseBudgetTest.php tests/Feature/BootstrapEnvFileTest.php tests/Feature/ProcessJobsCommandHardeningTest.php tests/Feature/AiWorkerCompositionTest.php tests/Feature/HostingerMediaRequirementsTest.php tests/Feature/HostingerFallbackConfigTest.php tests/Feature/FfmpegRequirementsTest.php tests/Unit/MediaConfigTest.php
git commit -m "docs: document hostinger smart reframe deployment"
~~~

---

### Task 11: FFmpeg real, E2E autenticado e freeze

**Files:**
- Modify: tests/Integration/SmartReframeFfmpegTest.php
- Create: tests/Integration/SmartReframeWorkflowTest.php
- Create: tests/Browser/smart-reframe-e2e.spec.mjs
- Create: tests/Browser/support/smart-reframe-e2e-fixture.php
- Modify: docs/superpowers/specs/2026-09-04-fase-5-mediapipe-smart-reframe-design.md somente se o gate revelar divergência factual comprovada.

**Interfaces:**
- Consumes: Tasks 1–10 completas.
- Produces: prova reproduzível de original, 9:16 center, 1:1 manual, 4:5 auto esquerda→direita, 16:9 de portrait e fluxo HTTP owner/foreign/guest.
- Produces: fixture CLI `smart-reframe-e2e-fixture.php setup <run_id>` e `cleanup <run_id>` que cria usuário/projeto/source/analysis/clip isolados, vídeo H.264/AAC baseado na face sintética e remove exatamente DB/storage/rate-limit desse run, sem Gemini nem estado demo prévio.
- Produces: baseline Phase 5 com localhost 127.0.0.1:8093 mantido aberto após o smoke.

- [ ] **Step 1: Completar e repetir a integração FFmpeg real**

Amplie o gate criado na Task 6 para duas fontes H.264/AAC determinísticas em diretório temporário: landscape com região vermelha à esquerda e azul à direita, usando `tests/Fixtures/mediapipe-synthetic-face.png` em movimento; portrait com marcadores equivalentes. Renderize os cinco casos e use FFprobe para codec, áudio, duration e dimensões. Extraia JPEG inicial/final do auto e compare canais médios para provar foco vermelho→azul. `tearDown` remove todos os arquivos, inclusive em falha.

~~~php
self::assertSame([720, 1280], $this->dimensions($vertical));
self::assertSame([720, 720], $this->dimensions($square));
self::assertSame([720, 900], $this->dimensions($automatic));
self::assertSame([1280, 720], $this->dimensions($portraitToLandscape));
self::assertTrue($first['red'] > $first['blue']);
self::assertTrue($last['blue'] > $last['red']);
~~~

- [ ] **Step 2: Executar FFmpeg real e confirmar resultado**

Run:

~~~powershell
$env:TEST_FFMPEG_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffmpeg.exe'
$env:TEST_FFPROBE_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffprobe.exe'
C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\SmartReframeFfmpegTest.php
~~~

Expected: PASS sem skip; MP4 é H.264/AAC, JPEG segue a proporção final e auto muda o foco. Paths locais ficam somente em env/comando, nunca no código.

- [ ] **Step 3: Escrever workflow completo no banco SQL suportado**

Seed owner, foreign user, projeto, source privado real ready com width/height, análise atual e clip suggested no schema `cliplab_phase5_test` após o guard `_test`. Gere a source H.264/AAC a partir da mesma fixture facial sintética, sem download/Gemini e sem reutilizar mídia ou clip demo. Conceda consentimento, peça preview 206, submeta plano auto, reivindique job, execute handler, consulte status e assets. Exija um job, um profile da revisão, 2..32 keyframes, completed, owner 200, foreign/stale 404, guest redirect/401, ledger do user igual, outbox do clip 0 e diretório temporário do run vazio; não use contagens globais. `finally` remove linhas e storage do run.

~~~php
self::assertSame(1, $this->jobCount($clipId, $revision));
self::assertSame(1, $this->profileCount($clipId, $revision));
self::assertSame($ledgerBefore, $this->ledgerCount($userId));
self::assertSame(0, $this->cleanupOutboxCountForClip($clipId));
~~~

- [ ] **Step 4: Criar E2E browser autenticado**

Antes de qualquer setup, o Node gera `runId = 'sr_' + crypto.randomUUID().replaceAll('-', '')`, valida `/^sr_[a-f0-9]{32}$/` e resolve `runRoot = path.resolve(TEST_MEDIA_PRIVATE_ROOT, runId)`, exigindo que continue filho do root base. Exija presença de todas as sete variáveis; para `TEST_DB_PASSWORD` aceite string vazia, para as demais exija valor não vazio. Valide também que o DSN contém `dbname` terminado em `_test` e que a fila media dedicada está vazia antes do insert.

Defina `testEnv` integralmente como allowlist de `PATH`, `SystemRoot`, `TEMP`, `TMP` e:

~~~javascript
const hasEnv = name => Object.prototype.hasOwnProperty.call(process.env, name);
const presentEnv = name => {
  if (!hasEnv(name)) throw new Error('Missing required test environment.');
  return process.env[name];
};
const requiredEnv = name => {
  const value = presentEnv(name);
  if (value.trim() === '') throw new Error('Missing required test environment.');
  return value;
};
const platformEnv = Object.fromEntries(
  ['PATH', 'SystemRoot', 'TEMP', 'TMP']
    .filter(hasEnv)
    .map(name => [name, process.env[name]])
);
const testEnv = {
  ...platformEnv,
  APP_ENV: 'testing',
  APP_DEBUG: 'false',
  APP_ENV_FILE: '',
  APP_TIMEZONE: 'UTC',
  DB_DSN: requiredEnv('TEST_DB_DSN'),
  DB_USERNAME: requiredEnv('TEST_DB_USERNAME'),
  DB_PASSWORD: presentEnv('TEST_DB_PASSWORD'),
  MEDIA_DISK: 'local',
  MEDIA_PRIVATE_ROOT: runRoot,
  FFMPEG_BINARY: requiredEnv('TEST_FFMPEG_BIN'),
  FFPROBE_BINARY: requiredEnv('TEST_FFPROBE_BIN'),
  MAIL_TRANSPORT: 'log',
  MAIL_FROM_ADDRESS: 'e2e@cliplab.test',
  MAIL_LOG_FILE: path.join(runRoot, 'mail.log'),
  GEMINI_API_KEY: '',
  GEMINI_MODEL: ''
};
const appEnv = {...testEnv, APP_URL: 'http://127.0.0.1:8095'};
~~~

O `beforeAll` chama `spawn(phpBin, ['tests/Browser/support/smart-reframe-e2e-fixture.php','setup',runId], {cwd:root,shell:false,windowsHide:true,env:testEnv})`, lê uma única linha JSON com IDs/credencial efêmera e inicia `startLocalPhpServer({phpBin,port:8095,root,env:appEnv})`. A fixture valida o run id, cria o vídeo com a face sintética, usa transação quando aplicável e, em catch/finally próprio, remove qualquer linha/arquivo parcial antes de sair nonzero; nunca imprime path/object key. Como o run id existe antes do setup, `afterAll` sempre executa `cleanup <runId>` mesmo se setup/JSON/server falhar e chama `server.stop()` quando iniciado. Antes do login, setup guarda a linha exata `rate_limits(action='login-ip', rate_key=sha256('127.0.0.1'))`; cleanup apaga a versão alterada e restaura esse snapshot quando existia, além de apagar `login-identity` para `sha256('127.0.0.1|' + emailDoRun)`. Não apague outras linhas de rate limit.

Use uma única sessão: login com o usuário isolado, abrir seu projeto elegível, confirmar que vendor não carregou antes do consentimento, carregar preview manual e provar que só a source foi pedida, conceder, ativar auto, esperar keyframes finitos e enviar render. O hook Node executa:

~~~javascript
const phpBin = requiredEnv('TEST_PHP_BIN');
const worker = spawn(
  phpBin,
  ['bin/process-jobs.php', '--queue=media', '--limit=1', '--time-budget=50'],
  {cwd: root, shell: false, windowsHide: true, env: appEnv}
);
~~~

Espere exit 0 e status completed, abra thumbnail/download e valide 320/768/1440. Ambos os specs Playwright usam seu próprio servidor helper (8094/8095), nunca o processo ambiente 8093. Intercepte toda rede, compare `url.origin === baseUrl` e falhe fora dele; não use Gemini.

- [ ] **Step 5: Executar integração e E2E**

Run:

~~~powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
$env:TEST_PHP_BIN='C:\xampp\php\php.exe'
$env:TEST_MEDIA_PRIVATE_ROOT=(Join-Path $env:TEMP 'cliplab-smart-reframe-e2e')
$env:TEST_FFMPEG_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffmpeg.exe'
$env:TEST_FFPROBE_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffprobe.exe'
C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration\SmartReframeWorkflowTest.php
pnpm exec playwright test tests\Browser\smart-reframe-e2e.spec.mjs
~~~

Expected: PASS sem Gemini, sem estado demo prévio e sem skip de DB/media/browser; cleanup deixa zero linha/arquivo do run E2E.

- [ ] **Step 6: Executar suites completas**

Run:

~~~powershell
$env:TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cliplab_phase5_test;charset=utf8mb4'
$env:TEST_DB_USERNAME='root'
$env:TEST_DB_PASSWORD=''
$env:TEST_PHP_BIN='C:\xampp\php\php.exe'
$env:TEST_MEDIA_PRIVATE_ROOT=(Join-Path $env:TEMP 'cliplab-smart-reframe-e2e')
$env:TEST_FFMPEG_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffmpeg.exe'
$env:TEST_FFPROBE_BIN='C:\Users\Acer\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-full_build\bin\ffprobe.exe'
C:\xampp\php\php.exe vendor\bin\phpunit tests\Unit
C:\xampp\php\php.exe vendor\bin\phpunit tests\Integration
C:\xampp\php\php.exe vendor\bin\phpunit tests\Feature
node tests\Browser\project-status-concurrency.test.js public\assets\js\project-status.js
node tests\Browser\clip-status.test.js public\assets\js\clip-status.js
node tests\Browser\reframe-tracker.test.mjs public\assets\js\reframe-tracker.js
node tests\Browser\reframe-worker.test.mjs public\assets\js\reframe-worker.js
node tests\Browser\reframe-editor.test.mjs public\assets\js\reframe-editor.js
pnpm exec playwright test tests\Browser\mediapipe-real.spec.mjs tests\Browser\smart-reframe-e2e.spec.mjs
node tools\vendor-mediapipe.mjs verify
C:\xampp\php\php.exe bin\check-requirements.php
~~~

Expected: zero failure e zero skip de banco, mídia ou browser.

- [ ] **Step 7: Executar lint, diff e scans**

Run:

~~~powershell
git diff --check 9c29e60
git diff --name-only 9c29e60 -- '*.php' | ForEach-Object { C:\xampp\php\php.exe -l $_ }
git status --short
git check-ignore .env storage node_modules
~~~

Faça scans count-only de segredo/chave privada em todos os arquivos rastreados. Para path absoluto, logs, temp e mídia inesperada, escaneie código/config de produção e exclua documentação, testes, fixture facial e vendor manifestado, onde paths de comando/proveniência são intencionais; o resultado de produção precisa ser zero. MP4/JPEG gerados ficam somente em roots temporários/privados ignorados. Nunca imprima o conteúdo de .env ou a chave Gemini.

- [ ] **Step 8: Solicitar revisão final independente**

Use superpowers:requesting-code-review contra base 9c29e60. O reviewer confere spec inteira, ownership, CSRF, Range, memória, transação, stale/legacy, filtergraph, argv, CSP, consentimento, privacidade, worker, cleanup, Hostinger, 320 px e testes reais. Findings aceitos voltam ao implementador responsável, com re-review e repetição dos gates afetados.

- [ ] **Step 9: Preparar e deixar demo localhost visível**

No banco local, crie uma nova sugestão eligible vinculada à análise atual do projeto demo; não reutilize clip completed. Reutilize o processo quando a porta 8093 já estiver respondendo; caso contrário inicie em background oculto com o binário configurável:

~~~powershell
$phpBin = if ([string]::IsNullOrWhiteSpace($env:TEST_PHP_BIN)) { 'C:\xampp\php\php.exe' } else { $env:TEST_PHP_BIN }
Start-Process -FilePath $phpBin -ArgumentList @('-S','127.0.0.1:8093','-t','public','public\index.php') -WorkingDirectory (Get-Location) -WindowStyle Hidden -PassThru
~~~

Abra http://127.0.0.1:8093/projetos/{id}, faça uma render auto completa, valide preview seek/status/thumbnail/download e deixe essa página aberta. Credenciais demo permanecem demo@cliplab.local / ClipLab#Demo2026; não exponha credenciais reais.

- [ ] **Step 10: Commit do gate**

~~~powershell
git add tests/Integration/SmartReframeFfmpegTest.php tests/Integration/SmartReframeWorkflowTest.php tests/Browser/smart-reframe-e2e.spec.mjs tests/Browser/support/smart-reframe-e2e-fixture.php docs/superpowers/specs/2026-09-04-fase-5-mediapipe-smart-reframe-design.md
git commit -m "test: verify smart reframe workflow"
~~~

- [ ] **Step 11: Registrar evidência final**

Exija git status vazio e registre no relatório/ledger ignorado: HEAD, totais Unit/Integration/Feature/Browser, FFmpeg/FFprobe, checker, manifest verify, URL/projeto demo, profile/job/ledger/outbox/temp. Não chame o SaaS completo: a entrega é a Fase 5 e legendas/editor/monetização/admin continuam no roadmap.

---

## Dependency Graph and Execution Order

~~~text
Task 1 → Task 2                         (runner de testes disponível)
Task 1 → Task 4                         (runner de testes disponível)
Task 2 → Task 3
Task 3 → Task 5
Task 2 → Task 6
Tasks 3 + 5 + 6 → Task 7
Tasks 2 + 3 + 4 + 5 + 7 → Task 8
Tasks 1 + 4 + 8 → Task 9
Tasks 1 + 2 + 3 + 4 + 9 → Task 10
Tasks 1–10 → Task 11
~~~

Execução escolhida pelo usuário: Subagent-Driven. Cada Task recebe implementador e reviewer independentes; tarefas que compartilham arquivos não rodam simultaneamente. As auditorias e testes sem mutação podem rodar em paralelo para reduzir o tempo total.

## Definition of Done

- Os cinco formatos e quatro modos renderizam com perfil exato por revisão.
- Auto usa MediaPipe real em module Worker; nenhuma inferência roda na main thread.
- Sem JavaScript/consentimento/codec, original/center/manual continuam utilizáveis.
- Preview owner-only implementa 200/206/416 com chunks <=1 MiB e 404 indistinguível.
- Consentimento antecede toda carga do SDK/modelo e pode ser revogado.
- Nenhum raw detection/frame/embedding/key/path aparece em DB, status ou logs.
- Job payload, fencing, retry/reclaim, cleanup/outbox e ledger da Fase 4 permanecem corretos.
- Assets fixos/hash/MIME/CSP/checker e runbook Hostinger passam offline em produção.
- Unit, Integration, Feature, CJS harnesses, Playwright, FFmpeg real, E2E, lint, diff e scans passam sem skips obrigatórios.
- Localhost 127.0.0.1:8093 fica ativo e aberto em uma tela demo funcional.
