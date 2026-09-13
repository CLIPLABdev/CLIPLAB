# Projetos e Processamento Inicial — Fase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar criação de projetos por upload ou URL HTTPS direta, armazenamento privado, fila MySQL acionada por cron, inspeção segura com FFprobe, biblioteca e status atualizado por polling.

**Architecture:** O monólito PHP permanece como plano de controle, com entradas validadas persistidas em storage privado e jobs idempotentes no MySQL. Um worker CLI finito usa lease/retry e delega inspeção ao contrato `MediaProcessor`, permitindo substituir o adaptador local por VPS e o disco local por S3 sem alterar controllers ou respostas públicas.

**Tech Stack:** PHP 8.0+, MySQL 8, PDO, `finfo`, cURL, `proc_open`, FFprobe, HTML5, CSS3, JavaScript puro, PHPUnit 9.6.

**Spec:** `docs/superpowers/specs/2026-09-03-fase-2-projetos-processamento-design.md`

## Global Constraints

- Produção deve funcionar em hospedagem compartilhada Hostinger sem Node.js, Docker, Redis, WebSocket ou processos permanentes.
- Aceitar somente upload MP4, MOV e WEBM e somente importação por URL HTTPS direta.
- Arquivos e temporários ficam sob `MEDIA_PRIVATE_ROOT`, nunca em `public/`; nomes do usuário nunca compõem caminhos físicos.
- O request web não executa FFprobe nem baixa integralmente uma URL remota.
- Toda consulta de projeto inclui `user_id`; recurso inexistente e recurso alheio retornam o mesmo 404 genérico.
- Toda mutação web usa CSRF; status JSON usa sessão autenticada e `Cache-Control: no-store`.
- Comandos externos usam argv separado, binário configurado, caminho confinado, timeout e limite de saída; nenhuma entrada passa por shell.
- Logs não incluem URL completa, query string, caminho absoluto, payload, token, chave de lease ou saída integral de processo.
- Jobs suportam invocações de cron simultâneas, interrupção, lease expirado, retry com backoff e conclusão idempotente.
- PHP mínimo é 8.0; testes usam PHPUnit 9.6 e integrações MySQL usam `TEST_DB_DSN`, `TEST_DB_USERNAME` e `TEST_DB_PASSWORD`.
- O frontend reutiliza o design system escuro e responsivo da Fase 1 e permanece funcional sem JavaScript.
- Ausência de `proc_open` ou FFprobe não derruba a aplicação web; a capacidade deve ser reportada e o contrato permite worker em VPS.

## File map and dependency graph

- Task 1 owns schema, configuration, immutable data objects and cross-task interfaces. It is blocking.
- Task 2 owns validation, private local storage, URL safety and `ProjectCreator` implementation.
- Task 3 owns the MySQL queue, lease protocol and finite CLI worker shell.
- Task 5 owns controllers/views/styles for library and creation, tested against the Task 1 interfaces.
- Task 4 owns safe process execution, FFprobe parsing and job handlers; it starts after Tasks 2 and 3.
- Task 6 owns composition in `routes/web.php`, status API, browser polling, dashboard CTAs, deployment docs and end-to-end verification; it starts after Tasks 2, 3, 4 and 5.

Execution waves: `Task 1` → parallel `Tasks 2 + 3 + 5` → `Task 4` → `Task 6`.

---

### Task 1: Schema, media configuration and stable contracts

**Dependencies:** blocking task; no Phase 2 task may begin before its review is clean.

**Files:**
- Create: `database/migrations/202609030002_create_project_processing_tables.sql`
- Create: `config/media.php`
- Create: `app/Contracts/PrivateStorage.php`
- Create: `app/Contracts/ProjectCreator.php`
- Create: `app/Contracts/JobDispatcher.php`
- Create: `app/Contracts/MediaProcessor.php`
- Create: `app/Media/StoredObject.php`
- Create: `app/Media/ProjectReceipt.php`
- Create: `app/Media/ProjectSource.php`
- Create: `app/Media/MediaMetadata.php`
- Modify: `.env.example`
- Test: `tests/Unit/MediaConfigTest.php`
- Test: `tests/Integration/ProjectProcessingMigrationTest.php`

**Interfaces:**
- Consumes: `Config::get(string $key, mixed $default = null): mixed`, `Database::connection(): PDO`, migration CLI from Phase 1.
- Produces: `PrivateStorage::putUploaded(string $temporaryPath, string $objectKey): StoredObject`, `PrivateStorage::putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject`, `PrivateStorage::absolutePath(string $objectKey): string`, `PrivateStorage::delete(string $objectKey): void`.
- Produces: `ProjectCreator::fromUpload(int $userId, array $input, array $file): ProjectReceipt`, `ProjectCreator::fromDirectUrl(int $userId, array $input): ProjectReceipt`.
- Produces: `JobDispatcher::dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int`.
- Produces: `MediaProcessor::inspect(ProjectSource $source): MediaMetadata`.
- Produces immutable PHP 8.0 objects with explicit constructors: `StoredObject(string $objectKey, int $sizeBytes, string $sha256)`, `ProjectReceipt(int $projectId, string $status, bool $created)`, `ProjectSource(int $id, int $projectId, string $storageDisk, string $objectKey, string $mimeType)`, `MediaMetadata(int $durationSeconds, int $width, int $height, string $videoCodec, ?string $audioCodec, bool $hasAudio)`.

- [ ] **Step 1: Write failing configuration and migration tests**

```php
public function testMediaDefaultsAreHostingerSafe(): void
{
    $config = require dirname(__DIR__, 2) . '/config/media.php';
    self::assertSame(['mp4', 'mov', 'webm'], $config['allowed_extensions']);
    self::assertSame(524288000, $config['max_upload_bytes']);
    self::assertGreaterThanOrEqual(60, $config['queue']['lease_seconds']);
    self::assertSame('local', $config['disk']);
    self::assertStringNotContainsString(DIRECTORY_SEPARATOR . 'public', $config['private_root']);
}
```

```php
public function testCreatesProjectSourcesAndProcessingJobsIdempotently(): void
{
    $this->migrator->run();
    $this->migrator->run();
    self::assertSame(['project_sources', 'processing_jobs'], $this->existingTables(['project_sources', 'processing_jobs']));
    self::assertTrue($this->hasUniqueIndex('processing_jobs', ['queue_name', 'idempotency_key']));
    self::assertTrue($this->hasUniqueIndex('projects', ['user_id', 'ingest_key']));
}
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run: `php vendor/bin/phpunit tests/Unit/MediaConfigTest.php tests/Integration/ProjectProcessingMigrationTest.php`

Expected: FAIL because `config/media.php` and migration `202609030002_create_project_processing_tables.sql` do not exist. The integration test skips only when `TEST_DB_DSN` is absent.

- [ ] **Step 3: Add the migration with explicit constraints and claim indexes**

```sql
ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS ingest_key CHAR(64) NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS progress TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS error_code VARCHAR(64) NULL AFTER progress,
    ADD COLUMN IF NOT EXISTS error_message VARCHAR(255) NULL AFTER error_code,
    ADD UNIQUE KEY uq_projects_user_ingest (user_id, ingest_key);

CREATE TABLE IF NOT EXISTS project_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    source_type ENUM('upload', 'direct_url') NOT NULL,
    storage_disk VARCHAR(32) NOT NULL DEFAULT 'local',
    object_key VARCHAR(255) NULL,
    original_name VARCHAR(255) NULL,
    extension VARCHAR(8) NULL,
    mime_type VARCHAR(100) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NULL,
    source_url TEXT NULL,
    source_host VARCHAR(255) NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    duration_seconds INT UNSIGNED NULL,
    video_codec VARCHAR(64) NULL,
    audio_codec VARCHAR(64) NULL,
    has_audio TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'stored', 'ready', 'failed') NOT NULL DEFAULT 'pending',
    fetched_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_sources_project (project_id),
    KEY idx_project_sources_status_created (status, created_at),
    CONSTRAINT fk_project_sources_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS processing_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_name VARCHAR(64) NOT NULL DEFAULT 'media',
    type VARCHAR(64) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    payload_json JSON NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    status ENUM('queued', 'running', 'retry', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL,
    worker_id VARCHAR(100) NULL,
    lease_token_hash CHAR(64) NULL,
    leased_until DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_error_code VARCHAR(64) NULL,
    last_error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_processing_jobs_queue_idempotency (queue_name, idempotency_key),
    KEY idx_processing_jobs_claim (queue_name, status, available_at, leased_until, id),
    KEY idx_processing_jobs_project (project_id, created_at),
    CONSTRAINT fk_processing_jobs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Migration tests must also assert `progress <= 100` at repository boundaries, foreign-key cascade, unique project source, and a second migration run with no duplicate DDL error.

- [ ] **Step 4: Add configuration and concrete interface definitions**

```php
return [
    'disk' => Env::get('MEDIA_DISK', 'local'),
    'private_root' => Env::get('MEDIA_PRIVATE_ROOT', dirname(__DIR__) . '/storage/media'),
    'max_upload_bytes' => max(1, (int) Env::get('MEDIA_MAX_UPLOAD_BYTES', '524288000')),
    'allowed_extensions' => ['mp4', 'mov', 'webm'],
    'download_timeout_seconds' => max(5, (int) Env::get('MEDIA_DOWNLOAD_TIMEOUT_SECONDS', '120')),
    'max_redirects' => max(0, (int) Env::get('MEDIA_MAX_REDIRECTS', '2')),
    'ffprobe_binary' => Env::get('FFPROBE_BINARY', 'ffprobe'),
    'process_timeout_seconds' => max(5, (int) Env::get('PROCESS_TIMEOUT_SECONDS', '60')),
    'process_output_limit_bytes' => max(4096, (int) Env::get('PROCESS_OUTPUT_LIMIT_BYTES', '1048576')),
    'queue' => [
        'lease_seconds' => max(60, (int) Env::get('QUEUE_LEASE_SECONDS', '300')),
        'max_attempts' => max(1, (int) Env::get('QUEUE_MAX_ATTEMPTS', '3')),
        'batch_size' => max(1, min(10, (int) Env::get('QUEUE_BATCH_SIZE', '1'))),
    ],
];
```

Add all signatures from **Interfaces** as real PHP interfaces and immutable objects with validating constructors. Add the exact environment names and production-safe comments to `.env.example`; do not add a real path, URL or secret.

- [ ] **Step 5: Run GREEN tests and lint new PHP files**

Run: `php vendor/bin/phpunit tests/Unit/MediaConfigTest.php tests/Integration/ProjectProcessingMigrationTest.php`

Run: `Get-ChildItem app/Contracts,app/Media,config -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }`

Expected: tests PASS (or the documented MySQL test skip), and every file reports `No syntax errors detected`.

- [ ] **Step 6: Commit the blocking contracts**

```bash
git add .env.example config/media.php database/migrations/202609030002_create_project_processing_tables.sql app/Contracts app/Media tests/Unit/MediaConfigTest.php tests/Integration/ProjectProcessingMigrationTest.php
git commit -m "feat: define project processing contracts"
```

### Task 2: Validated intake and private local storage

**Dependencies:** Task 1. May run in parallel with Tasks 3 and 5 after Task 1 is approved.

**Files:**
- Create: `app/Exceptions/MediaValidationException.php`
- Create: `app/Media/UploadValidator.php`
- Create: `app/Media/DirectUrlValidator.php`
- Create: `app/Media/PinnedHttpDownloader.php`
- Create: `app/Storage/LocalPrivateStorage.php`
- Create: `app/Repositories/ProjectSourceRepository.php`
- Modify: `app/Repositories/ProjectRepository.php`
- Create: `app/Services/ProjectIntakeService.php`
- Create: `storage/media/.gitignore`
- Test: `tests/Unit/UploadValidatorTest.php`
- Test: `tests/Unit/DirectUrlValidatorTest.php`
- Test: `tests/Unit/LocalPrivateStorageTest.php`
- Test: `tests/Integration/ProjectIntakeServiceTest.php`

**Interfaces:**
- Consumes: `PrivateStorage`, `ProjectCreator`, `JobDispatcher`, `StoredObject`, `ProjectReceipt` from Task 1.
- Produces: `UploadValidator::validate(array $file): ValidatedUpload`, `DirectUrlValidator::validate(string $url): ValidatedRemoteUrl`, `PinnedHttpDownloader::download(ValidatedRemoteUrl $url, PrivateStorage $storage, string $objectKey, int $maxBytes): StoredObject`.
- Produces: `ProjectSourceRepository::findForOwnedProject(int $projectId, int $userId): ?ProjectSource`, `ProjectSourceRepository::markStored(int $sourceId, StoredObject $object, string $mimeType, string $extension): void`.
- Produces: `ProjectIntakeService implements ProjectCreator`; Task 5 consumes only the `ProjectCreator` interface.

- [ ] **Step 1: Write failing tests for file spoofing, traversal and SSRF**

```php
public function testRejectsMp4ExtensionWithHtmlMimeAndSignature(): void
{
    $file = $this->fakeUpload('attack.mp4', '<html>not video</html>', 'video/mp4');
    $this->expectException(MediaValidationException::class);
    $this->validator->validate($file);
}

public function testRejectsPrivateAndCredentialedHttpsUrls(): void
{
    foreach (['https://127.0.0.1/video.mp4', 'https://user:pass@example.com/video.mp4', 'http://example.com/video.mp4'] as $url) {
        try {
            $this->validator->validate($url);
            self::fail('Unsafe URL accepted');
        } catch (MediaValidationException $exception) {
            self::assertSame('unsafe_source_url', $exception->publicCode());
        }
    }
}

public function testObjectKeyCannotEscapePrivateRoot(): void
{
    $this->expectException(MediaValidationException::class);
    $this->storage->absolutePath('../public/leak.mp4');
}
```

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php vendor/bin/phpunit tests/Unit/UploadValidatorTest.php tests/Unit/DirectUrlValidatorTest.php tests/Unit/LocalPrivateStorageTest.php`

Expected: FAIL because validators and local storage do not exist.

- [ ] **Step 3: Implement bounded validation and atomic private writes**

```php
$extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, ['mp4', 'mov', 'webm'], true)) {
    throw MediaValidationException::withCode('unsupported_extension');
}
$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
$allowed = [
    'mp4' => ['video/mp4', 'application/mp4'],
    'mov' => ['video/quicktime', 'video/mp4'],
    'webm' => ['video/webm'],
];
if (!is_string($mime) || !in_array($mime, $allowed[$extension], true) || !$this->signatureMatches($temporaryPath, $extension)) {
    throw MediaValidationException::withCode('invalid_media_container');
}
```

`LocalPrivateStorage` must reject empty/absolute/dot-segment keys, create a random `.part` sibling, stream in fixed chunks while hashing and counting, stop above `maxBytes`, flush and atomically rename only after success. `putUploaded()` additionally requires `is_uploaded_file()` in production and accepts an injected verifier in tests. Cleanup removes only the verified `.part`/final path under the configured root.

- [ ] **Step 4: Implement direct URL validation and pinned downloading**

```php
$parts = parse_url(trim($url));
if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
    || isset($parts['user'], $parts['pass']) || ($parts['host'] ?? '') === '') {
    throw MediaValidationException::withCode('unsafe_source_url');
}
$addresses = ($this->resolver)((string) $parts['host']);
foreach ($addresses as $address) {
    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        throw MediaValidationException::withCode('unsafe_source_url');
    }
}
```

`PinnedHttpDownloader` must use cURL TLS verification, pin only validated public IPs while retaining the original host for SNI, disable automatic redirects, validate each `Location` with the same validator, allow at most configured redirects, reject oversized `Content-Length`, cap streamed bytes independently, and accept only the MIME/container combinations from `UploadValidator`. Unit tests inject DNS and HTTP transports; they must cover IPv4/IPv6 private ranges, redirect to metadata/localhost, DNS with any private answer, timeout, missing/false length, oversized stream and HTML response.

- [ ] **Step 5: Write RED integration tests for idempotent upload and URL registration**

```php
public function testRepeatedUploadReceiptDoesNotDuplicateRowsOrJobs(): void
{
    $input = ['name' => 'Podcast 01', 'idempotency_key' => 'browser-key-123'];
    $first = $this->service->fromUpload($this->userId, $input, $this->validMp4());
    $second = $this->service->fromUpload($this->userId, $input, $this->validMp4());
    self::assertTrue($first->created);
    self::assertFalse($second->created);
    self::assertSame($first->projectId, $second->projectId);
    self::assertSame(1, $this->countRows('project_sources', $first->projectId));
    self::assertSame(1, $this->countRows('processing_jobs', $first->projectId));
}
```

Run: `php vendor/bin/phpunit tests/Integration/ProjectIntakeServiceTest.php`

Expected: FAIL because `ProjectIntakeService` is missing.

- [ ] **Step 6: Implement transaction boundaries and compensate storage failures**

Hash the browser idempotency key as `hash('sha256', $userId . ':' . $key)`. For upload, validate and store before the short DB transaction; inside it, insert-or-load the owned project, source and `probe_source` job. On DB failure for a newly stored object, delete that exact object. For URL, validate without transferring, then create project/source and `fetch_and_probe` atomically. Never hold a transaction during file copy, DNS, HTTP or process execution. Never put `source_url` in a log context.

- [ ] **Step 7: Run GREEN tests and commit**

Run: `php vendor/bin/phpunit tests/Unit/UploadValidatorTest.php tests/Unit/DirectUrlValidatorTest.php tests/Unit/LocalPrivateStorageTest.php tests/Integration/ProjectIntakeServiceTest.php`

Expected: all unit tests PASS; integration PASS with MySQL or skips only for absent `TEST_DB_DSN`.

```bash
git add app/Exceptions app/Media app/Storage app/Repositories app/Services storage/media tests/Unit tests/Integration/ProjectIntakeServiceTest.php
git commit -m "feat: add secure project intake"
```

### Task 3: Lease-based MySQL queue and finite cron worker

**Dependencies:** Task 1. May run in parallel with Tasks 2 and 5 after Task 1 is approved.

**Files:**
- Create: `app/Queue/ClaimedJob.php`
- Create: `app/Queue/JobOutcome.php`
- Create: `app/Queue/JobHandler.php`
- Create: `app/Repositories/ProcessingJobRepository.php`
- Create: `app/Services/DatabaseJobDispatcher.php`
- Create: `app/Services/QueueWorker.php`
- Create: `bin/process-jobs.php`
- Test: `tests/Unit/QueueWorkerTest.php`
- Test: `tests/Integration/ProcessingJobRepositoryTest.php`
- Test: `tests/Feature/ProcessJobsCommandTest.php`

**Interfaces:**
- Consumes: `JobDispatcher` and queue config from Task 1.
- Produces: `ProcessingJobRepository::claimNext(string $queue, string $workerId, int $leaseSeconds): ?ClaimedJob`, `complete(ClaimedJob $job): bool`, `retry(ClaimedJob $job, string $code, string $publicMessage, DateTimeImmutable $availableAt): bool`, `fail(ClaimedJob $job, string $code, string $publicMessage): bool`.
- Produces: `JobHandler::handle(ClaimedJob $job): JobOutcome`, where outcome is exactly `completed`, `retry` or `failed` with public code/message.
- Produces: `QueueWorker::run(string $queue, int $limit, int $timeBudgetSeconds): WorkerReport` and finite CLI `php bin/process-jobs.php --queue=media --limit=1 --time-budget=50`.

- [ ] **Step 1: Write failing lease and stale-worker integration tests**

```php
public function testOnlyOneWorkerClaimsAnEligibleJob(): void
{
    $jobId = $this->insertQueuedJob();
    $first = $this->repositoryA->claimNext('media', 'worker-a', 120);
    $second = $this->repositoryB->claimNext('media', 'worker-b', 120);
    self::assertSame($jobId, $first?->id);
    self::assertNull($second);
}

public function testExpiredLeaseCanBeReclaimedAndOldTokenCannotComplete(): void
{
    $old = $this->claimExpiredFixture('worker-old');
    $new = $this->repository->claimNext('media', 'worker-new', 120);
    self::assertNotNull($new);
    self::assertFalse($this->repository->complete($old));
    self::assertTrue($this->repository->complete($new));
}
```

- [ ] **Step 2: Run repository tests and verify RED**

Run: `php vendor/bin/phpunit tests/Integration/ProcessingJobRepositoryTest.php`

Expected: FAIL because `ProcessingJobRepository` and `ClaimedJob` do not exist.

- [ ] **Step 3: Implement transactional claiming and token-guarded transitions**

Within one short transaction, select one eligible row in ID order using `FOR UPDATE`, including `queued/retry` rows whose `available_at <= UTC_TIMESTAMP()` and `running` rows whose `leased_until < UTC_TIMESTAMP()`. Generate a 32-byte token, persist only `hash('sha256', $token)`, increment `attempts`, set lease fields and commit. Return the plaintext token only in `ClaimedJob` memory.

Every terminal/retry update must include:

```sql
WHERE id = :id
  AND status = 'running'
  AND worker_id = :worker_id
  AND lease_token_hash = :lease_token_hash
  AND leased_until >= UTC_TIMESTAMP()
```

`retry()` must refuse when `attempts >= max_attempts` and atomically mark `failed`. Backoff is `min(900, 15 * (2 ** max(0, attempts - 1)))` seconds. Store only allowlisted public codes and messages no longer than 255 characters.

- [ ] **Step 4: Write failing worker behavior tests**

```php
public function testRetriesTransientOutcomeAndContinuesUntilLimit(): void
{
    $handler = new FakeJobHandler([JobOutcome::retry('processor_unavailable', 'Processador temporariamente indisponível.')]);
    $report = $this->worker($handler)->run('media', 1, 50);
    self::assertSame(1, $report->retried);
    self::assertSame(0, $report->completed);
}

public function testStopsBeforeStartingAnotherJobAfterTimeBudget(): void
{
    $clock = new AdvancingClock([0, 49, 51]);
    $report = $this->worker($this->successHandler(), $clock)->run('media', 10, 50);
    self::assertSame(1, $report->claimed);
}
```

Run: `php vendor/bin/phpunit tests/Unit/QueueWorkerTest.php tests/Feature/ProcessJobsCommandTest.php`

Expected: FAIL because worker and command are missing.

- [ ] **Step 5: Implement dispatcher, worker and defensive CLI parsing**

`DatabaseJobDispatcher::dispatch()` performs insert-or-load using the unique queue/idempotency constraint and JSON encoding with `JSON_THROW_ON_ERROR`. `QueueWorker` checks the deadline before every claim, dispatches by exact job type, catches exceptions at the job boundary, maps only known transient failures to retry, and never logs payload/token. Unknown job types fail permanently as `unsupported_job_type`.

The CLI accepts only `--queue=media`, integer `--limit=1..10`, and `--time-budget=5..240`; invalid options exit `2`. Normal execution prints one JSON summary without sensitive fields and exits `0`; bootstrap/database failure exits `1`.

- [ ] **Step 6: Run GREEN tests and commit**

Run: `php vendor/bin/phpunit tests/Unit/QueueWorkerTest.php tests/Integration/ProcessingJobRepositoryTest.php tests/Feature/ProcessJobsCommandTest.php`

Expected: all tests PASS; MySQL concurrency tests skip only when integration environment variables are absent.

```bash
git add app/Queue app/Repositories/ProcessingJobRepository.php app/Services/DatabaseJobDispatcher.php app/Services/QueueWorker.php bin/process-jobs.php tests/Unit/QueueWorkerTest.php tests/Integration/ProcessingJobRepositoryTest.php tests/Feature/ProcessJobsCommandTest.php
git commit -m "feat: add lease based processing queue"
```

### Task 4: Safe ProcessRunner, FFprobe and ingestion handlers

**Dependencies:** Tasks 2 and 3.

**Files:**
- Create: `app/Process/ProcessResult.php`
- Create: `app/Process/ProcessRunner.php`
- Create: `app/Media/LocalFfprobeProcessor.php`
- Create: `app/Queue/ProbeSourceHandler.php`
- Create: `app/Queue/FetchAndProbeHandler.php`
- Modify: `app/Repositories/ProjectSourceRepository.php`
- Modify: `app/Repositories/ProjectRepository.php`
- Test: `tests/Unit/ProcessRunnerTest.php`
- Test: `tests/Unit/LocalFfprobeProcessorTest.php`
- Test: `tests/Unit/ProbeSourceHandlerTest.php`
- Test: `tests/Unit/FetchAndProbeHandlerTest.php`

**Interfaces:**
- Consumes: `PrivateStorage`, `MediaProcessor`, `ProjectSource`, `MediaMetadata`, `PinnedHttpDownloader`, `JobHandler`, `JobOutcome`.
- Produces: `ProcessRunner::run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult`.
- Produces: `LocalFfprobeProcessor implements MediaProcessor`.
- Produces: handlers for exactly `probe_source` and `fetch_and_probe`.
- Produces: `ProjectSourceRepository::markReady(int $sourceId, MediaMetadata $metadata): void`, `ProjectRepository::updateProcessingState(int $projectId, string $status, int $progress, ?string $errorCode = null, ?string $publicMessage = null): void`.

- [ ] **Step 1: Write failing process isolation tests**

```php
public function testPassesArgumentsWithoutShellInterpolation(): void
{
    $result = $this->runner->run([$this->phpBinary, '-r', 'echo $argv[1];', 'name;echo injected'], 5, 4096);
    self::assertSame(0, $result->exitCode);
    self::assertSame('name;echo injected', $result->stdout);
}

public function testTerminatesOnTimeoutAndCapsCombinedOutput(): void
{
    $this->expectException(ProcessExecutionException::class);
    $this->runner->run([$this->fixtureBinary, 'unbounded-output'], 1, 4096);
}
```

- [ ] **Step 2: Run ProcessRunner tests and verify RED**

Run: `php vendor/bin/phpunit tests/Unit/ProcessRunnerTest.php`

Expected: FAIL because `ProcessRunner` does not exist.

- [ ] **Step 3: Implement argv-only execution with timeout and output caps**

Use `proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true])` with `$command` as an array. Reject an empty command, non-string arguments and a binary different from the constructor allowlist. Set stdout/stderr nonblocking, use `stream_select`, track elapsed monotonic time and combined bytes, terminate on timeout/overflow, close every pipe, and return exit code plus bounded output. Exceptions expose stable codes (`process_unavailable`, `process_timeout`, `process_output_limit`, `process_failed`) without command/path/output in their public message.

- [ ] **Step 4: Write failing FFprobe contract tests**

```php
public function testBuildsFixedFfprobeArgumentsAndParsesMetadata(): void
{
    $this->runner->willReturn(new ProcessResult(0, $this->fixture('ffprobe-valid.json'), ''));
    $metadata = $this->processor->inspect($this->source);
    self::assertSame(1920, $metadata->width);
    self::assertSame(1080, $metadata->height);
    self::assertSame(126, $metadata->durationSeconds);
    self::assertSame('h264', $metadata->videoCodec);
    self::assertTrue($metadata->hasAudio);
    self::assertSame([
        $this->ffprobeBinary, '-v', 'error', '-print_format', 'json',
        '-show_format', '-show_streams', $this->privateAbsolutePath,
    ], $this->runner->lastCommand());
}
```

Also test invalid JSON, no video stream, negative/NaN duration, dimensions above 16384, path outside storage, nonzero exit and oversized output.

Run: `php vendor/bin/phpunit tests/Unit/LocalFfprobeProcessorTest.php`

Expected: FAIL because `LocalFfprobeProcessor` is missing.

- [ ] **Step 5: Implement FFprobe parsing and both job handlers**

`LocalFfprobeProcessor` resolves the path through `PrivateStorage`, invokes the exact fixed argument vector above, decodes with `JSON_THROW_ON_ERROR`, chooses the first video stream and optional first audio stream, and validates duration `1..86400`, dimensions `1..16384` and codec strings against `[a-zA-Z0-9_.-]{1,64}`.

`ProbeSourceHandler` sets project `probing/70`, inspects, then transactionally updates source metadata/status and project `ready/100`. `FetchAndProbeHandler` sets `fetching/20`, downloads and validates to a random object key, marks source stored, then invokes the same probe operation. Permanent media validation returns `failed`; network timeout and unavailable processor return `retry`. If DB persistence fails after a new download, delete only that newly created object.

- [ ] **Step 6: Run handler tests and the Phase 2 focused suite**

Run: `php vendor/bin/phpunit tests/Unit/ProcessRunnerTest.php tests/Unit/LocalFfprobeProcessorTest.php tests/Unit/ProbeSourceHandlerTest.php tests/Unit/FetchAndProbeHandlerTest.php`

Expected: PASS including success, permanent failure, retry and cleanup paths.

- [ ] **Step 7: Commit**

```bash
git add app/Process app/Media/LocalFfprobeProcessor.php app/Queue app/Repositories tests/Unit/ProcessRunnerTest.php tests/Unit/LocalFfprobeProcessorTest.php tests/Unit/ProbeSourceHandlerTest.php tests/Unit/FetchAndProbeHandlerTest.php
git commit -m "feat: inspect project sources with ffprobe"
```

### Task 5: Project library and accessible creation UI

**Dependencies:** Task 1. May run in parallel with Tasks 2 and 3 after Task 1 is approved; tests use a fake `ProjectCreator`.

**Files:**
- Create: `app/Controllers/ProjectController.php`
- Create: `app/Validation/ProjectValidator.php`
- Create: `app/Views/projects/index.php`
- Create: `app/Views/projects/create.php`
- Create: `public/assets/js/projects.js`
- Modify: `public/assets/css/app.css`
- Test: `tests/Unit/ProjectValidatorTest.php`
- Test: `tests/Unit/ProjectControllerTest.php`
- Test: `tests/Feature/ProjectViewsTest.php`

**Interfaces:**
- Consumes: `ProjectCreator` and `ProjectReceipt` from Task 1, existing `Request`, `Response`, `Session`, `View`, CSRF middleware and authenticated layout.
- Produces: `ProjectController::index(): Response`, `ProjectController::create(): Response`, `ProjectController::store(Request $request): Response`.
- Produces: forms posting `multipart/form-data` to `/projetos` with fields `_csrf`, `idempotency_key`, `name`, `source_type`, `video_file`, `source_url`.
- Produces: cards with `data-project-id`, and for nonterminal states a placeholder `data-project-status-url="/api/projects/{id}/status"` consumed by Task 6.

- [ ] **Step 1: Write failing validation and controller tests**

```php
public function testRequiresExactlyOneSourceForSelectedMode(): void
{
    self::assertArrayHasKey('video_file', ProjectValidator::creation([
        'name' => 'Entrevista', 'source_type' => 'upload', 'source_url' => 'https://example.com/video.mp4',
    ], []));
    self::assertArrayHasKey('source_url', ProjectValidator::creation([
        'name' => 'Entrevista', 'source_type' => 'direct_url',
    ], []));
}

public function testStorePassesAuthenticatedOwnerAndRedirectsToLibrary(): void
{
    Session::put('user_id', 42);
    $response = $this->controller->store(Request::fake('POST', '/projetos', [
        'name' => 'Cortes do episódio', 'source_type' => 'direct_url',
        'source_url' => 'https://cdn.example.test/episode.mp4', 'idempotency_key' => 'browser-key',
    ]));
    self::assertSame(42, $this->creator->lastUserId());
    self::assertSame('/projetos?created=1', $response->header('Location'));
}
```

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php vendor/bin/phpunit tests/Unit/ProjectValidatorTest.php tests/Unit/ProjectControllerTest.php tests/Feature/ProjectViewsTest.php`

Expected: FAIL because controller, validator and views do not exist.

- [ ] **Step 3: Implement controller boundaries and recoverable errors**

`create()` generates `bin2hex(random_bytes(24))` as the idempotency key. `store()` reads `$_FILES['video_file']` only for upload mode, validates display-level fields, calls the matching `ProjectCreator` method and maps known `MediaValidationException` codes to Portuguese form messages. It preserves `name` and selected mode; it does not repopulate a URL containing userinfo or query parameters. It never accepts `user_id`, storage key, status or MIME from request input.

`index()` obtains rows through a repository callable that always receives `Session::get('user_id')`, orders by newest first and caps the first page at 24 records. A missing/inactive session remains guarded by `AuthMiddleware` at route composition in Task 6.

- [ ] **Step 4: Build library, dual-mode form and responsive styling**

The library must render an honest empty state, create CTA, and real cards with escaped name, upload/URL label, duration, size, creation date, status and progress. The creation page uses accessible tab buttons controlling panels, but both panels remain reachable and the server handles the default when JavaScript is disabled. Set `accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm"`; show configured byte limit rendered by the server; do not claim unsupported social-platform imports.

`projects.js` only switches visible mode, updates required attributes and file-name feedback. Keyboard focus, visible focus ring, `aria-selected`, `aria-controls` and reduced-motion behavior are required. No polling belongs in this task.

- [ ] **Step 5: Run GREEN UI tests and PHP lint**

Run: `php vendor/bin/phpunit tests/Unit/ProjectValidatorTest.php tests/Unit/ProjectControllerTest.php tests/Feature/ProjectViewsTest.php`

Run: `php -l app/Controllers/ProjectController.php; php -l app/Views/projects/index.php; php -l app/Views/projects/create.php`

Expected: all tests PASS and no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add app/Controllers/ProjectController.php app/Validation/ProjectValidator.php app/Views/projects public/assets/js/projects.js public/assets/css/app.css tests/Unit/ProjectValidatorTest.php tests/Unit/ProjectControllerTest.php tests/Feature/ProjectViewsTest.php
git commit -m "feat: add project library and intake form"
```

### Task 6: Composition, ownership status API, polling, CTAs and Hostinger runbook

**Dependencies:** Tasks 2, 3, 4 and 5.

**Files:**
- Create: `app/Controllers/ProjectStatusController.php`
- Create: `app/Services/ProjectStatusService.php`
- Create: `public/assets/js/project-status.js`
- Modify: `app/Repositories/ProjectRepository.php`
- Modify: `app/Controllers/DashboardController.php`
- Modify: `app/Views/dashboard/index.php`
- Modify: `app/Views/layouts/app.php`
- Modify: `routes/web.php`
- Modify: `bin/check-requirements.php`
- Modify: `docs/HOSTINGER.md`
- Modify: `README.md`
- Test: `tests/Unit/ProjectStatusServiceTest.php`
- Test: `tests/Feature/ProjectStatusEndpointTest.php`
- Test: `tests/Feature/ProjectWorkflowTest.php`
- Test: `tests/Feature/HostingerMediaRequirementsTest.php`

**Interfaces:**
- Consumes: all approved Task 1 contracts and concrete adapters from Tasks 2–5.
- Produces: `ProjectStatusService::forOwnedProject(int $projectId, int $userId): ?array` with exact keys `id`, `status`, `progress`, `stage`, `message`, `media`, `updated_at`.
- Produces: authenticated routes `GET /projetos`, `GET /projetos/novo`, `POST /projetos`, `GET /api/projects/{id}/status`.
- Produces: Task 3 CLI wired to `probe_source` and `fetch_and_probe` handlers.

- [ ] **Step 1: Write failing ownership and response-minimization tests**

```php
public function testOwnerReceivesOnlyPublicStatusFields(): void
{
    $response = $this->getAs($this->ownerId, '/api/projects/' . $this->projectId . '/status');
    self::assertSame(200, $response->status());
    self::assertSame('no-store', $response->header('Cache-Control'));
    $json = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    self::assertSame(['id', 'status', 'progress', 'stage', 'message', 'media', 'updated_at'], array_keys($json));
    self::assertArrayNotHasKey('source_url', $json);
    self::assertArrayNotHasKey('object_key', $json['media']);
}

public function testAnotherUserGetsSameNotFoundAsUnknownProject(): void
{
    $foreign = $this->getAs($this->otherUserId, '/api/projects/' . $this->projectId . '/status');
    $unknown = $this->getAs($this->otherUserId, '/api/projects/999999999/status');
    self::assertSame(404, $foreign->status());
    self::assertSame($unknown->body(), $foreign->body());
}
```

- [ ] **Step 2: Run endpoint tests and verify RED**

Run: `php vendor/bin/phpunit tests/Unit/ProjectStatusServiceTest.php tests/Feature/ProjectStatusEndpointTest.php`

Expected: FAIL because status service/controller and route do not exist.

- [ ] **Step 3: Implement owned status projection and JSON response support**

Add `Response::json(array $payload, int $status = 200): Response` only if the existing `Response` still lacks it; encode with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` and set `Content-Type: application/json; charset=UTF-8` plus `X-Content-Type-Options: nosniff`.

The repository query must join `projects` and `project_sources` with `WHERE projects.id = :project_id AND projects.user_id = :user_id`. Map status to fixed Portuguese `stage/message` strings. `media` may contain only nullable `duration_seconds`, `width`, `height`, `video_codec`, `audio_codec`, `has_audio`, `size_bytes` and `original_name`. Return the same `{"error":"project_not_found"}` body for unknown/foreign IDs.

- [ ] **Step 4: Compose routes, factories and the real job-handler map**

In `routes/web.php`, build lazy PDO-backed factories following the existing dashboard/auth pattern. Apply `$authenticated` to all four routes. `POST /projetos` relies on the global CSRF middleware already installed by the front controller. Wire the CLI handler map exactly as:

```php
$handlers = [
    'probe_source' => new ProbeSourceHandler($projects, $sources, $mediaProcessor, $pdo),
    'fetch_and_probe' => new FetchAndProbeHandler($projects, $sources, $downloader, $storage, $mediaProcessor, $pdo),
];
```

No web route may call `QueueWorker::run()` or `MediaProcessor::inspect()`.

- [ ] **Step 5: Write failing polling and full-workflow feature tests**

```php
public function testDashboardAndEmptyLibraryLinkToProjectCreation(): void
{
    self::assertStringContainsString('href="/projetos/novo"', $this->getAs($this->userId, '/dashboard')->body());
    self::assertStringContainsString('href="/projetos/novo"', $this->getAs($this->userId, '/projetos')->body());
}

public function testUploadCreatesQueuedProjectWhichWorkerMarksReady(): void
{
    $created = $this->postUploadAs($this->userId, '/projetos', $this->validMp4Fixture());
    self::assertSame('/projetos?created=1', $created->header('Location'));
    $this->runWorkerWithFixtureFfprobe(1);
    $status = $this->statusJsonForNewestProject($this->userId);
    self::assertSame('ready', $status['status']);
    self::assertSame(100, $status['progress']);
    self::assertSame(1280, $status['media']['width']);
}
```

Run: `php vendor/bin/phpunit tests/Feature/ProjectWorkflowTest.php`

Expected: FAIL before CTAs, composition and worker wiring are complete.

- [ ] **Step 6: Add bounded, accessible polling and activate dashboard CTAs**

`project-status.js` discovers only elements with `data-project-status-url`. Poll immediately, then after 3 seconds; back off to 5, 8 and 15 seconds on unchanged/error responses, pause when `document.hidden`, and stop for `ready`, `failed`, 401, 404 or five consecutive errors. Update status text and progress locally; announce only stage changes through one `aria-live="polite"` region. Use `AbortController` with a 10-second fetch timeout. Never construct a URL from untrusted JSON.

Replace both disabled dashboard buttons with anchors to `/projetos/novo`; add “Projetos” to app navigation; load `projects.js` only on creation and `project-status.js` only where status cards exist. Server-rendered status remains the fallback when JavaScript is disabled.

- [ ] **Step 7: Extend requirement checks and Hostinger documentation**

`bin/check-requirements.php` reports: `fileinfo`, `curl`, upload/post limits, writable private storage, PHP CLI, `proc_open` availability and an argv-only FFprobe `-version` probe with timeout. It must not fail the whole site check solely because local processing is unavailable; instead print `WARN` plus the exact action to configure a VPS worker.

`docs/HOSTINGER.md` must include:

- private root placement outside `public_html` where available, permissions and quota check;
- `.env` media/queue variables without secrets;
- effective upload limits and the need for `post_max_size` above `upload_max_filesize`;
- one-minute cron command `php /absolute/project/bin/process-jobs.php --queue=media --limit=1 --time-budget=50`;
- behavior when PHP CLI, `proc_open` or FFprobe is unavailable;
- contract-based move to `RemoteMediaProcessor` on a VPS and `S3PrivateStorage`, with signed short-lived object access;
- deploy order: backup, upload code, Composer production install, migration, requirements check, cron, smoke test;
- rollback note: stop cron before code/database rollback and preserve private media.

Update `README.md` with local FFprobe setup, Phase 2 commands and honest statement that this phase inspects sources but does not generate final clips.

- [ ] **Step 8: Run the complete test, lint and security verification**

Run sequentially to avoid integration-table lock contention:

```powershell
php vendor/bin/phpunit tests/Unit
php vendor/bin/phpunit tests/Integration
php vendor/bin/phpunit tests/Feature
Get-ChildItem app,bootstrap,bin,config,public,routes,tests -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php bin/check-requirements.php
```

Expected: all unit/feature tests PASS; MySQL integrations PASS when test variables are set or report explicit skips when absent; all PHP files lint clean; checker reports storage and dependencies and uses WARN for unavailable FFprobe/process capability.

Run: `rg -n "source_url|lease_token|payload_json|object_key|MEDIA_PRIVATE_ROOT" app/Views public/assets storage/logs -g '!*.map'`

Expected: no sensitive field rendered or logged; permitted matches are only fixed data attributes/configuration names, never values.

Run: `git diff --check; git status --short`

Expected: no whitespace errors and only intended Phase 2 files before commit.

- [ ] **Step 9: Commit the integrated workflow**

```bash
git add app/Controllers app/Services/ProjectStatusService.php app/Repositories/ProjectRepository.php app/Views/dashboard app/Views/layouts/app.php public/assets routes/web.php bin docs README.md tests
git commit -m "feat: integrate project processing workflow"
```

## Review checkpoints

After each task, run its focused tests and obtain an independent specification/code-quality review before starting a dependent task. After Task 1 is clean, dispatch Tasks 2, 3 and 5 in separate isolated worktrees; merge each reviewed commit before Task 4. After Task 6, review the complete diff from the Phase 1 baseline, resolve findings in one fix pass, re-run the complete suite sequentially, and verify the real localhost journey at `http://127.0.0.1:8088/projetos/novo`.
