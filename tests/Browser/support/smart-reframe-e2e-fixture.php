<?php

declare(strict_types=1);

use App\Core\Migrator;
use App\Process\ProcessRunner;
use App\Storage\LocalPrivateStorage;
use Tests\Support\SafePhase5TestDatabase;

$projectRoot = dirname(__DIR__, 3);
require $projectRoot . '/vendor/autoload.php';

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
        throw new RuntimeException('invalid_invocation');
    }
    $action = is_string($argv[1] ?? null) ? $argv[1] : '';
    $runId = is_string($argv[2] ?? null) ? $argv[2] : '';
    if (!in_array($action, ['setup', 'inspect', 'cleanup'], true)
        || preg_match('/\Asr_[a-f0-9]{32}\z/D', $runId) !== 1) {
        throw new RuntimeException('invalid_invocation');
    }

    $identity = fixtureIdentity($runId);
    $runRoot = fixtureRunRoot($runId, $projectRoot);
    $tree = null;
    $state = null;
    if ($action !== 'setup') {
        $tree = fixtureSafeTree($runRoot);
        if ($action === 'cleanup' && $tree['directories'] === []) {
            echo json_encode(['ok' => true, 'run_id' => $runId], JSON_THROW_ON_ERROR) . PHP_EOL;
            exit(0);
        }
        $state = fixtureReadState($runRoot, true, $tree);
    }

    $pdo = fixtureDatabase();
    (new Migrator($pdo, $projectRoot . '/database/migrations'))->run();

    if ($action === 'setup') {
        $result = fixtureSetup($pdo, $runRoot, $projectRoot, $identity);
    } elseif ($action === 'inspect') {
        $result = fixtureInspect($pdo, $runRoot, $identity, $state, $tree);
    } else {
        fixtureCleanup($pdo, $runRoot, $identity, $state, $tree);
        $result = ['ok' => true, 'run_id' => $runId];
    }

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Smart reframe fixture failed.\n");
    exit(1);
}

/** @return array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} */
function fixtureIdentity(string $runId): array
{
    $hex = substr($runId, 3);

    return [
        'run_id' => $runId,
        'plan_slug' => 'sr-' . $hex,
        'owner_email' => 'sr-owner+' . $hex . '@clipforge.test',
        'foreign_email' => 'sr-foreign+' . $hex . '@clipforge.test',
        'owner_password' => 'ClipForge#' . substr($hex, 0, 16),
        'foreign_password' => 'ClipForge#' . substr($hex, 16, 16),
        'ingest_key' => hash('sha256', 'smart-reframe-e2e-' . $runId),
    ];
}

function fixtureRunRoot(string $runId, string $projectRoot): string
{
    $configured = getenv('MEDIA_PRIVATE_ROOT');
    $authorized = getenv('TEST_MEDIA_PRIVATE_ROOT');
    if (!is_string($configured) || trim($configured) === '' || strpos($configured, "\0") !== false
        || !is_string($authorized) || trim($authorized) === '' || strpos($authorized, "\0") !== false) {
        throw new RuntimeException('invalid_root');
    }
    $publicRoot = realpath($projectRoot . '/public');
    if ($publicRoot === false) {
        throw new RuntimeException('invalid_root');
    }
    $authorizedRoot = fixtureValidatedPath($authorized, $publicRoot);
    $root = fixtureValidatedPath($configured, $publicRoot);
    $expectedRoot = $authorizedRoot . DIRECTORY_SEPARATOR . $runId;
    if (basename($root) !== $runId
        || fixtureComparablePath($root) !== fixtureComparablePath($expectedRoot)
        || !fixturePathWithin($root, $authorizedRoot)) {
        throw new RuntimeException('invalid_root');
    }

    return $expectedRoot;
}

function fixtureLexicalPath(string $path): string
{
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim(trim($path), '/\\'));
    $absolute = DIRECTORY_SEPARATOR === '\\'
        ? preg_match('#\A[A-Za-z]:[\\\\/]#', $normalized) === 1
        : str_starts_with($normalized, '/');
    $tail = DIRECTORY_SEPARATOR === '\\' ? substr($normalized, 3) : substr($normalized, 1);
    if (!$absolute || str_contains($normalized, "\0") || $tail === '') {
        throw new RuntimeException('invalid_root');
    }
    foreach (explode(DIRECTORY_SEPARATOR, $tail) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('invalid_root');
        }
    }

    return $normalized;
}

function fixtureValidatedPath(string $path, string $publicRoot): string
{
    $candidate = fixtureLexicalPath($path);
    $cursor = $candidate;
    $missing = [];
    while (!file_exists($cursor) && !is_link($cursor)) {
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            throw new RuntimeException('invalid_root');
        }
        array_unshift($missing, basename($cursor));
        $cursor = $parent;
    }
    fixtureValidateExistingSegments($cursor);
    $canonical = realpath($cursor);
    if ($canonical === false
        || fixtureComparablePath($canonical) !== fixtureComparablePath($cursor)) {
        throw new RuntimeException('invalid_root');
    }
    $projected = rtrim($canonical, '/\\');
    foreach ($missing as $segment) {
        $projected .= DIRECTORY_SEPARATOR . $segment;
    }
    if (fixtureComparablePath($projected) !== fixtureComparablePath($candidate)
        || fixturePathWithin($projected, $publicRoot)
        || dirname($projected) === $projected) {
        throw new RuntimeException('invalid_root');
    }

    return $projected;
}

function fixtureValidateExistingSegments(string $path): void
{
    $path = fixtureLexicalPath($path);
    $root = DIRECTORY_SEPARATOR === '\\' ? substr($path, 0, 3) : DIRECTORY_SEPARATOR;
    $tail = DIRECTORY_SEPARATOR === '\\' ? substr($path, 3) : substr($path, 1);
    $current = $root;
    foreach (explode(DIRECTORY_SEPARATOR, $tail) as $segment) {
        $current = rtrim($current, '/\\') . DIRECTORY_SEPARATOR . $segment;
        $canonical = realpath($current);
        if ($canonical === false || fixtureIsReparse($current) || !is_dir($current)
            || fixtureComparablePath($canonical) !== fixtureComparablePath($current)) {
            throw new RuntimeException('invalid_root');
        }
    }
}

function fixtureCreateRunRoot(string $runRoot): void
{
    $cursor = $runRoot;
    $missing = [];
    while (!file_exists($cursor) && !is_link($cursor)) {
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            throw new RuntimeException('fixture_storage_failed');
        }
        array_unshift($missing, basename($cursor));
        $cursor = $parent;
    }
    fixtureValidateExistingSegments($cursor);
    $current = $cursor;
    foreach ($missing as $segment) {
        $current = rtrim($current, '/\\') . DIRECTORY_SEPARATOR . $segment;
        if (!mkdir($current, 0700) || fixtureIsReparse($current)) {
            throw new RuntimeException('fixture_storage_failed');
        }
        fixtureValidateExistingSegments($current);
    }
    if (fixtureComparablePath($current) !== fixtureComparablePath($runRoot)) {
        throw new RuntimeException('fixture_storage_failed');
    }
}

function fixtureComparablePath(string $path): string
{
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($path, '/\\'));

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function fixturePathWithin(string $candidate, string $root): bool
{
    $candidate = fixtureComparablePath($candidate);
    $root = fixtureComparablePath($root);

    return $candidate === $root || str_starts_with($candidate, $root . DIRECTORY_SEPARATOR);
}

function fixtureIsReparse(string $path): bool
{
    $stat = lstat($path);
    if ($stat === false || is_link($path)) {
        return true;
    }

    return DIRECTORY_SEPARATOR === '\\' && is_dir($path) && (((int) ($stat['mode'] ?? 0)) & 0xF000) !== 0x4000;
}

function fixtureDatabase(): PDO
{
    $dsn = SafePhase5TestDatabase::validatedDsn(getenv('DB_DSN'));
    $username = getenv('DB_USERNAME');
    $password = getenv('DB_PASSWORD');
    if (!is_string($username) || trim($username) === '' || !is_string($password)) {
        throw new RuntimeException('invalid_database');
    }
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'clipforge_phase5_test') {
        throw new RuntimeException('invalid_database');
    }

    return $pdo;
}

/**
 * @param array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} $identity
 * @return array<string, mixed>
 */
function fixtureSetup(PDO $pdo, string $runRoot, string $projectRoot, array $identity): array
{
    $activeJobs = (int) $pdo->query(
        "SELECT COUNT(*) FROM processing_jobs WHERE queue_name = 'media' AND status IN ('queued','running','retry')"
    )->fetchColumn();
    if ($activeJobs !== 0 || file_exists($runRoot)) {
        throw new RuntimeException('fixture_not_isolated');
    }
    fixtureCreateRunRoot($runRoot);
    fixtureSafeTree($runRoot);

    $rateSpecs = fixtureRateSpecs($identity);
    $rateSnapshots = array_map(static fn (array $spec): array => $spec + [
        'row' => fixtureRateSnapshot($pdo, $spec['action'], $spec['rate_key']),
    ], $rateSpecs);
    $state = [
        'version' => 1,
        'run_id' => $identity['run_id'],
        'plan_id' => 0,
        'owner_id' => 0,
        'foreign_id' => 0,
        'project_id' => 0,
        'clip_id' => 0,
        'owner_email' => $identity['owner_email'],
        'foreign_email' => $identity['foreign_email'],
        'plan_slug' => $identity['plan_slug'],
        'ingest_key' => $identity['ingest_key'],
        'ledger_count' => 0,
        'media_job_ids_before' => fixtureMediaJobIds($pdo),
        'rate_snapshots' => $rateSnapshots,
    ];

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO plans (slug, name, credits, features) VALUES (?, ?, 10, JSON_OBJECT())'
        )->execute([$identity['plan_slug'], 'Smart reframe E2E ' . substr($identity['run_id'], -8)]);
        $state['plan_id'] = (int) $pdo->lastInsertId();

        $state['owner_id'] = fixtureInsertUser(
            $pdo,
            'Smart reframe owner',
            $identity['owner_email'],
            $identity['owner_password'],
            (int) $state['plan_id'],
            10
        );
        $state['foreign_id'] = fixtureInsertUser(
            $pdo,
            'Smart reframe foreign',
            $identity['foreign_email'],
            $identity['foreign_password'],
            (int) $state['plan_id'],
            0
        );
        $pdo->prepare(
            "INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) "
            . "VALUES (?, 'credit', 10, 10, 'test_fixture', 'Smart reframe E2E credits')"
        )->execute([(int) $state['owner_id']]);
        $state['ledger_count'] = fixtureScalar(
            $pdo,
            'SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?',
            [(int) $state['owner_id']]
        );

        $pdo->prepare(
            "INSERT INTO projects (user_id, ingest_key, name, status, progress) "
            . "VALUES (?, ?, 'Smart reframe E2E', 'suggestions_ready', 100)"
        )->execute([(int) $state['owner_id'], $identity['ingest_key']]);
        $state['project_id'] = (int) $pdo->lastInsertId();

        $storage = new LocalPrivateStorage($runRoot, 64 * 1024 * 1024);
        $sourceKey = 'sources/' . $state['project_id'] . '/source-' . $identity['run_id'] . '.mp4';
        $sourcePath = $storage->absolutePath($sourceKey);
        if (!mkdir(dirname($sourcePath), 0700, true) && !is_dir(dirname($sourcePath))) {
            throw new RuntimeException('fixture_storage_failed');
        }
        fixtureGenerateSource($runRoot, $projectRoot, $sourcePath);
        $sourceSize = filesize($sourcePath);
        $sourceHash = hash_file('sha256', $sourcePath);
        if (!is_int($sourceSize) || $sourceSize < 1 || !is_string($sourceHash)) {
            throw new RuntimeException('fixture_media_failed');
        }

        $pdo->prepare(
            "INSERT INTO project_sources "
            . "(project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, sha256, "
            . "width, height, duration_seconds, video_codec, audio_codec, has_audio, status) "
            . "VALUES (?, 'upload', 'local', ?, 'smart-reframe.mp4', 'mp4', 'video/mp4', ?, ?, "
            . "640, 360, 3, 'h264', 'aac', 1, 'ready')"
        )->execute([(int) $state['project_id'], $sourceKey, $sourceSize, $sourceHash]);
        $pdo->prepare(
            "INSERT INTO ai_analyses "
            . "(project_id, prompt_version, model, status, video_summary, validated_response_json, completed_at) "
            . "VALUES (?, ?, 'offline-e2e', 'completed', 'Synthetic browser fixture', JSON_OBJECT('clips', JSON_ARRAY()), UTC_TIMESTAMP())"
        )->execute([(int) $state['project_id'], 'e2e-' . substr($identity['run_id'], -16)]);
        $analysisId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO clips "
            . "(project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, "
            . "viral_score, hook, reason, category, status) "
            . "VALUES (?, ?, 0, 'Smart reframe browser clip', 0.000, 2.000, 2.000, 94, "
            . "'Synthetic browser hook', 'Synthetic browser reason', 'insight', 'suggested')"
        )->execute([(int) $state['project_id'], $analysisId]);
        $state['clip_id'] = (int) $pdo->lastInsertId();
        $pdo->commit();

        fixtureWriteState($runRoot, $state);

        return [
            'run_id' => $identity['run_id'],
            'owner' => [
                'id' => $state['owner_id'],
                'email' => $identity['owner_email'],
                'password' => $identity['owner_password'],
            ],
            'foreign' => [
                'id' => $state['foreign_id'],
                'email' => $identity['foreign_email'],
                'password' => $identity['foreign_password'],
            ],
            'project_id' => $state['project_id'],
            'clip_id' => $state['clip_id'],
            'ledger_count' => $state['ledger_count'],
            'source' => ['width' => 640, 'height' => 360, 'duration_seconds' => 3, 'size_bytes' => $sourceSize],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fixtureCleanupAfterSetupFailure($pdo, $runRoot, $identity, $state);
        throw $exception;
    }
}

function fixtureInsertUser(PDO $pdo, string $name, string $email, string $password, int $planId, int $credits): int
{
    $statement = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $planId, $credits]);

    return (int) $pdo->lastInsertId();
}

function fixtureGenerateSource(string $runRoot, string $projectRoot, string $sourcePath): void
{
    $ffmpeg = fixtureRequiredBinary('FFMPEG_BINARY');
    $ffprobe = fixtureRequiredBinary('FFPROBE_BINARY');
    $portrait = realpath($projectRoot . '/tests/Fixtures/mediapipe-synthetic-face.png');
    if ($portrait === false || !is_file($portrait)) {
        throw new RuntimeException('fixture_media_failed');
    }
    $runner = new ProcessRunner([$ffmpeg, $ffprobe], $runRoot);
    $result = $runner->run([
        $ffmpeg,
        '-y', '-nostdin', '-hide_banner', '-loglevel', 'error',
        '-f', 'lavfi', '-i', 'color=color=red:size=640x360:rate=30:duration=3',
        '-loop', '1', '-i', $portrait,
        '-f', 'lavfi', '-i', 'sine=frequency=740:sample_rate=48000:duration=3',
        '-filter_complex', '[0:v]drawbox=x=320:y=0:w=320:h=360:color=blue:t=fill[bg];'
            . '[1:v]scale=180:180[face];[bg][face]overlay=x=30+(W-w-60)*t/3:y=(H-h)/2:shortest=1[outv]',
        '-map', '[outv]', '-map', '2:a:0', '-t', '3.000',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '21', '-pix_fmt', 'yuv420p',
        '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $sourcePath,
    ], 30, 1024 * 1024);
    if ($result->exitCode !== 0) {
        throw new RuntimeException('fixture_media_failed');
    }
    $probe = $runner->run([
        $ffprobe,
        '-v', 'error', '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height',
        '-of', 'json', $sourcePath,
    ], 15, 1024 * 1024);
    if ($probe->exitCode !== 0) {
        throw new RuntimeException('fixture_media_failed');
    }
    $metadata = json_decode($probe->stdout, true, 32, JSON_THROW_ON_ERROR);
    $video = fixtureStream($metadata, 'video');
    $audio = fixtureStream($metadata, 'audio');
    $duration = (float) ($metadata['format']['duration'] ?? 0.0);
    if (($video['codec_name'] ?? null) !== 'h264' || (int) ($video['width'] ?? 0) !== 640
        || (int) ($video['height'] ?? 0) !== 360 || ($audio['codec_name'] ?? null) !== 'aac'
        || abs($duration - 3.0) > 0.2) {
        throw new RuntimeException('fixture_media_failed');
    }
}

/** @param mixed $metadata @return array<string, mixed> */
function fixtureStream(mixed $metadata, string $type): array
{
    if (!is_array($metadata)) {
        return [];
    }
    foreach (($metadata['streams'] ?? []) as $stream) {
        if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
            return $stream;
        }
    }

    return [];
}

function fixtureRequiredBinary(string $name): string
{
    $configured = getenv($name);
    $resolved = is_string($configured) ? realpath($configured) : false;
    if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
        throw new RuntimeException('fixture_binary_failed');
    }

    return $resolved;
}

/** @return array<string, mixed>|null */
function fixtureRateSnapshot(PDO $pdo, string $action, string $rateKey): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, rate_key, action, window_started_at, attempts, expires_at, created_at, updated_at '
        . 'FROM rate_limits WHERE action = ? AND rate_key = ? LIMIT 1'
    );
    $statement->execute([$action, $rateKey]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} $identity
 * @return list<array{action:string,rate_key:string}>
 */
function fixtureRateSpecs(array $identity): array
{
    return [
        ['action' => 'login-ip', 'rate_key' => hash('sha256', '127.0.0.1')],
        ['action' => 'login-identity', 'rate_key' => hash('sha256', '127.0.0.1|' . $identity['owner_email'])],
        ['action' => 'login-identity', 'rate_key' => hash('sha256', '127.0.0.1|' . $identity['foreign_email'])],
    ];
}

/**
 * @param array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} $identity
 * @param array<string,mixed>|null $state
 * @return array<string,mixed>
 */
function fixtureValidateStateShape(?array $state, array $identity): array
{
    if (!is_array($state)
        || ($state['version'] ?? null) !== 1
        || ($state['run_id'] ?? null) !== $identity['run_id']
        || ($state['plan_slug'] ?? null) !== $identity['plan_slug']
        || ($state['owner_email'] ?? null) !== $identity['owner_email']
        || ($state['foreign_email'] ?? null) !== $identity['foreign_email']
        || ($state['ingest_key'] ?? null) !== $identity['ingest_key']) {
        throw new RuntimeException('fixture_state_invalid');
    }
    foreach (['plan_id', 'owner_id', 'foreign_id', 'project_id', 'clip_id'] as $field) {
        if (!is_int($state[$field] ?? null) || $state[$field] < 1) {
            throw new RuntimeException('fixture_state_invalid');
        }
    }
    if (!is_int($state['ledger_count'] ?? null) || $state['ledger_count'] < 0) {
        throw new RuntimeException('fixture_state_invalid');
    }

    $baselineIds = $state['media_job_ids_before'] ?? null;
    if (!is_array($baselineIds) || array_values($baselineIds) !== $baselineIds) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $knownIds = [];
    foreach ($baselineIds as $id) {
        if (!is_int($id) || $id < 1 || isset($knownIds[(string) $id])) {
            throw new RuntimeException('fixture_state_invalid');
        }
        $knownIds[(string) $id] = true;
    }

    $snapshots = $state['rate_snapshots'] ?? null;
    $expected = fixtureRateSpecs($identity);
    if (!is_array($snapshots) || array_values($snapshots) !== $snapshots
        || count($snapshots) !== count($expected)) {
        throw new RuntimeException('fixture_state_invalid');
    }
    foreach ($expected as $index => $spec) {
        $snapshot = $snapshots[$index] ?? null;
        if (!is_array($snapshot)
            || ($snapshot['action'] ?? null) !== $spec['action']
            || ($snapshot['rate_key'] ?? null) !== $spec['rate_key']) {
            throw new RuntimeException('fixture_state_invalid');
        }
        fixtureValidateRateRow($snapshot['row'] ?? null, $spec);
    }

    return $state;
}

/** @param mixed $row @param array{action:string,rate_key:string} $spec */
function fixtureValidateRateRow(mixed $row, array $spec): void
{
    if ($row === null) {
        return;
    }
    if (!is_array($row)
        || !is_int($row['id'] ?? null) || $row['id'] < 1
        || ($row['action'] ?? null) !== $spec['action']
        || ($row['rate_key'] ?? null) !== $spec['rate_key']
        || !is_string($row['window_started_at'] ?? null)
        || !is_int($row['attempts'] ?? null) || $row['attempts'] < 0
        || !is_string($row['expires_at'] ?? null)
        || !is_string($row['created_at'] ?? null)
        || !is_string($row['updated_at'] ?? null)) {
        throw new RuntimeException('fixture_state_invalid');
    }
}

/**
 * @param array<string,mixed> $state
 * @param array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} $identity
 * @return array<string,mixed>
 */
function fixtureValidateStateDatabase(PDO $pdo, array $state, array $identity): array
{
    fixtureRow(
        $pdo,
        'SELECT id FROM plans WHERE id = ? AND slug = ?',
        [$state['plan_id'], $identity['plan_slug']]
    );
    fixtureRow(
        $pdo,
        'SELECT id FROM users WHERE id = ? AND email = ? AND plan_id = ?',
        [$state['owner_id'], $identity['owner_email'], $state['plan_id']]
    );
    fixtureRow(
        $pdo,
        'SELECT id FROM users WHERE id = ? AND email = ? AND plan_id = ?',
        [$state['foreign_id'], $identity['foreign_email'], $state['plan_id']]
    );
    fixtureRow(
        $pdo,
        'SELECT p.id FROM projects p INNER JOIN users u ON u.id = p.user_id '
            . 'WHERE p.id = ? AND p.user_id = ? AND p.ingest_key = ? AND u.email = ?',
        [$state['project_id'], $state['owner_id'], $identity['ingest_key'], $identity['owner_email']]
    );
    fixtureRow(
        $pdo,
        'SELECT id FROM clips WHERE id = ? AND project_id = ?',
        [$state['clip_id'], $state['project_id']]
    );
    $sourceKey = 'sources/' . $state['project_id'] . '/source-' . $identity['run_id'] . '.mp4';
    fixtureRow(
        $pdo,
        "SELECT id FROM project_sources WHERE project_id = ? AND storage_disk = 'local' "
            . "AND object_key = ? AND status = 'ready'",
        [$state['project_id'], $sourceKey]
    );

    $projects = fixtureRows(
        $pdo,
        'SELECT p.id FROM projects p INNER JOIN users u ON u.id = p.user_id '
            . 'WHERE p.ingest_key = ? AND u.id = ? AND u.email = ?',
        [$identity['ingest_key'], $state['owner_id'], $identity['owner_email']]
    );
    if (count($projects) !== 1 || (int) $projects[0]['id'] !== $state['project_id']) {
        throw new RuntimeException('fixture_state_invalid');
    }

    return $state;
}

/**
 * @param array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} $identity
 * @param array<string,mixed>|null $state
 * @param array{files:list<string>,directories:list<string>}|null $tree
 */
function fixtureCleanup(PDO $pdo, string $runRoot, array $identity, ?array $state, ?array $tree): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!is_array($tree) || $tree['directories'] === []) {
        throw new RuntimeException('fixture_cleanup_failed');
    }
    $state = fixtureValidateStateDatabase($pdo, fixtureValidateStateShape($state, $identity), $identity);
    $projectId = (int) $state['project_id'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE FROM render_artifact_cleanups WHERE object_key LIKE ? OR object_key LIKE ?'
        )->execute(['processed/' . $projectId . '/%', 'thumbnails/' . $projectId . '/%']);
        $deleteProject = $pdo->prepare(
            'DELETE p FROM projects p INNER JOIN users u ON u.id = p.user_id '
                . 'WHERE p.id = ? AND p.ingest_key = ? AND u.id = ? AND u.email = ?'
        );
        $deleteProject->execute([
            $projectId, $identity['ingest_key'], $state['owner_id'], $identity['owner_email'],
        ]);
        if ($deleteProject->rowCount() !== 1) {
            throw new RuntimeException('fixture_state_invalid');
        }
        foreach ([
            [$state['owner_id'], $identity['owner_email']],
            [$state['foreign_id'], $identity['foreign_email']],
        ] as [$userId, $email]) {
            $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ? AND email = ? AND plan_id = ?');
            $deleteUser->execute([$userId, $email, $state['plan_id']]);
            if ($deleteUser->rowCount() !== 1) {
                throw new RuntimeException('fixture_state_invalid');
            }
        }
        $deletePlan = $pdo->prepare('DELETE FROM plans WHERE id = ? AND slug = ?');
        $deletePlan->execute([$state['plan_id'], $identity['plan_slug']]);
        if ($deletePlan->rowCount() !== 1) {
            throw new RuntimeException('fixture_state_invalid');
        }
        foreach ($state['rate_snapshots'] as $snapshot) {
            fixtureRestoreRateSnapshot($pdo, $snapshot);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    fixtureRemoveDirectory($tree);
}

/**
 * @param array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} $identity
 * @param array<string,mixed> $state
 */
function fixtureCleanupAfterSetupFailure(PDO $pdo, string $runRoot, array $identity, array $state): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $tree = fixtureSafeTree($runRoot);
    if ($tree['directories'] === []) {
        return;
    }

    try {
        $validated = fixtureValidateStateDatabase(
            $pdo,
            fixtureValidateStateShape($state, $identity),
            $identity
        );
    } catch (RuntimeException $exception) {
        $projectCount = fixtureScalar(
            $pdo,
            'SELECT COUNT(*) FROM projects p INNER JOIN users u ON u.id = p.user_id '
                . 'WHERE p.ingest_key = ? AND u.email IN (?, ?)',
            [$identity['ingest_key'], $identity['owner_email'], $identity['foreign_email']]
        );
        $userCount = fixtureScalar(
            $pdo,
            'SELECT COUNT(*) FROM users WHERE email IN (?, ?)',
            [$identity['owner_email'], $identity['foreign_email']]
        );
        $planCount = fixtureScalar(
            $pdo,
            'SELECT COUNT(*) FROM plans WHERE slug = ?',
            [$identity['plan_slug']]
        );
        if ($projectCount !== 0 || $userCount !== 0 || $planCount !== 0) {
            throw $exception;
        }
        fixtureRemoveDirectory($tree);

        return;
    }

    fixtureCleanup($pdo, $runRoot, $identity, $validated, $tree);
}

/** @param mixed $snapshot */
function fixtureRestoreRateSnapshot(PDO $pdo, mixed $snapshot): void
{
    if (!is_array($snapshot)
        || !is_string($snapshot['action'] ?? null)
        || !is_string($snapshot['rate_key'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['rate_key']) !== 1) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $pdo->prepare('DELETE FROM rate_limits WHERE action = ? AND rate_key = ?')
        ->execute([$snapshot['action'], $snapshot['rate_key']]);
    $row = $snapshot['row'] ?? null;
    if ($row === null) {
        return;
    }
    if (!is_array($row)
        || (string) ($row['action'] ?? '') !== $snapshot['action']
        || (string) ($row['rate_key'] ?? '') !== $snapshot['rate_key']) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $pdo->prepare(
        'INSERT INTO rate_limits '
        . '(id, rate_key, action, window_started_at, attempts, expires_at, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int) $row['id'], (string) $row['rate_key'], (string) $row['action'],
        (string) $row['window_started_at'], (int) $row['attempts'], (string) $row['expires_at'],
        (string) $row['created_at'], (string) $row['updated_at'],
    ]);
}

/**
 * @param array{run_id:string,plan_slug:string,owner_email:string,foreign_email:string,owner_password:string,foreign_password:string,ingest_key:string} $identity
 * @param array<string,mixed>|null $state
 * @param array{files:list<string>,directories:list<string>}|null $tree
 * @return array<string, mixed>
 */
function fixtureInspect(PDO $pdo, string $runRoot, array $identity, ?array $state, ?array $tree): array
{
    if (!is_array($tree) || $tree['directories'] === []) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $state = fixtureValidateStateDatabase($pdo, fixtureValidateStateShape($state, $identity), $identity);
    $clipId = (int) $state['clip_id'];
    $projectId = (int) $state['project_id'];
    $ownerId = (int) $state['owner_id'];
    $baselineIds = $state['media_job_ids_before'] ?? null;
    $knownIds = [];
    foreach ($baselineIds as $id) {
        $knownIds[(string) $id] = true;
    }

    $clip = fixtureRow(
        $pdo,
        'SELECT status, render_revision, output_file, output_size_bytes, thumbnail, thumbnail_size_bytes '
        . 'FROM clips WHERE id = ? AND project_id = ?',
        [$clipId, $projectId]
    );
    $jobs = fixtureRows(
        $pdo,
        "SELECT id, project_id, queue_name, type, status, payload_json "
        . "FROM processing_jobs WHERE queue_name = 'media' ORDER BY id",
        []
    );
    $newJobs = array_values(array_filter(
        $jobs,
        static fn (array $job): bool => !isset($knownIds[(string) $job['id']])
    ));
    $job = count($newJobs) === 1 ? $newJobs[0] : null;
    $jobPayload = is_array($job)
        ? json_decode((string) $job['payload_json'], true, 16, JSON_THROW_ON_ERROR)
        : null;
    $profile = fixtureRows(
        $pdo,
        'SELECT id, aspect_ratio, reframe_mode FROM clip_render_profiles WHERE clip_id = ? AND render_revision = ?',
        [$clipId, (int) $clip['render_revision']]
    );
    $keyframeCount = $profile === [] ? 0 : fixtureScalar(
        $pdo,
        'SELECT COUNT(*) FROM clip_reframe_keyframes WHERE render_profile_id = ?',
        [(int) $profile[0]['id']]
    );
    $outboxCount = fixtureScalar(
        $pdo,
        'SELECT COUNT(*) FROM render_artifact_cleanups WHERE object_key LIKE ? OR object_key LIKE ?',
        ['processed/' . $projectId . '/' . $clipId . '-%', 'thumbnails/' . $projectId . '/' . $clipId . '-%']
    );
    $storage = new LocalPrivateStorage($runRoot, 64 * 1024 * 1024);
    $videoExists = is_string($clip['output_file'] ?? null)
        && $clip['output_file'] !== '' && is_file($storage->absolutePath($clip['output_file']));
    $thumbnailExists = is_string($clip['thumbnail'] ?? null)
        && $clip['thumbnail'] !== '' && is_file($storage->absolutePath($clip['thumbnail']));

    return [
        'run_id' => $identity['run_id'],
        'clip_status' => (string) $clip['status'],
        'render_revision' => (int) $clip['render_revision'],
        'job_count' => count($newJobs),
        'job_id' => is_array($job) ? (int) $job['id'] : null,
        'job_project_id' => is_array($job) ? (int) $job['project_id'] : null,
        'job_queue_name' => is_array($job) ? (string) $job['queue_name'] : null,
        'job_type' => is_array($job) ? (string) $job['type'] : null,
        'job_status' => is_array($job) ? (string) $job['status'] : null,
        'job_payload' => $jobPayload,
        'profile_count' => count($profile),
        'aspect_ratio' => $profile === [] ? null : (string) $profile[0]['aspect_ratio'],
        'reframe_mode' => $profile === [] ? null : (string) $profile[0]['reframe_mode'],
        'keyframe_count' => $keyframeCount,
        'ledger_count' => fixtureScalar($pdo, 'SELECT COUNT(*) FROM credit_transactions WHERE user_id = ?', [$ownerId]),
        'outbox_count' => $outboxCount,
        'temporary_count' => fixtureTemporaryCount($tree),
        'video_exists' => $videoExists,
        'thumbnail_exists' => $thumbnailExists,
        'video_size_bytes' => (int) ($clip['output_size_bytes'] ?? 0),
        'thumbnail_size_bytes' => (int) ($clip['thumbnail_size_bytes'] ?? 0),
    ];
}

/** @param array<string,mixed> $state */
function fixtureWriteState(string $runRoot, array $state): void
{
    $path = $runRoot . DIRECTORY_SEPARATOR . 'fixture-state.json';
    $temporary = $path . '.tmp';
    $payloadJson = fixtureCanonicalJson($state);
    $json = fixtureCanonicalJson([
        'payload' => $state,
        'mac' => hash_hmac('sha256', $payloadJson, fixtureStateKey()),
    ]);
    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('fixture_state_failed');
    }
}

/**
 * @param array{files:list<string>,directories:list<string>}|null $tree
 * @return array<string,mixed>|null
 */
function fixtureReadState(string $runRoot, bool $required, ?array $tree): ?array
{
    $path = $runRoot . DIRECTORY_SEPARATOR . 'fixture-state.json';
    $approved = false;
    foreach (($tree['files'] ?? []) as $file) {
        if (fixtureComparablePath($file) === fixtureComparablePath($path)) {
            $approved = true;
            break;
        }
    }
    if (!$approved) {
        if ($required) {
            throw new RuntimeException('fixture_state_invalid');
        }

        return null;
    }
    $before = lstat($path);
    if ($before === false || fixtureIsReparse($path)
        || (((int) ($before['mode'] ?? 0)) & 0xF000) !== 0x8000) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $contents = file_get_contents($path);
    $after = lstat($path);
    if (!is_string($contents) || strlen($contents) > 65536 || $after === false) {
        throw new RuntimeException('fixture_state_invalid');
    }
    foreach (['dev', 'ino', 'mode', 'size', 'mtime'] as $field) {
        if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
            throw new RuntimeException('fixture_state_invalid');
        }
    }
    if ((int) ($after['size'] ?? -1) !== strlen($contents)) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $envelope = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($envelope) || count($envelope) !== 2
        || !array_key_exists('payload', $envelope) || !is_array($envelope['payload'])
        || !array_key_exists('mac', $envelope) || !is_string($envelope['mac'])
        || preg_match('/\A[a-f0-9]{64}\z/D', $envelope['mac']) !== 1) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $expected = hash_hmac('sha256', fixtureCanonicalJson($envelope['payload']), fixtureStateKey());
    if (!hash_equals($expected, $envelope['mac'])) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $state = $envelope['payload'];
    if (($state['version'] ?? null) !== 1) {
        throw new RuntimeException('fixture_state_invalid');
    }

    return $state;
}

function fixtureStateKey(): string
{
    $hex = getenv('SMART_REFRAME_FIXTURE_STATE_KEY');
    if (!is_string($hex) || preg_match('/\A[a-f0-9]{64}\z/D', $hex) !== 1) {
        throw new RuntimeException('fixture_state_invalid');
    }
    $key = hex2bin($hex);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('fixture_state_invalid');
    }

    return $key;
}

function fixtureCanonicalJson(mixed $value): string
{
    return json_encode(
        fixtureCanonicalValue($value),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}

function fixtureCanonicalValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (fixtureArrayIsList($value)) {
        return array_map(static fn (mixed $item): mixed => fixtureCanonicalValue($item), $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = fixtureCanonicalValue($item);
    }

    return $value;
}

/** @param array<mixed> $value */
function fixtureArrayIsList(array $value): bool
{
    $expected = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        ++$expected;
    }

    return true;
}

/** @param list<mixed> $parameters */
function fixtureScalar(PDO $pdo, string $sql, array $parameters): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}

/** @param list<mixed> $parameters @return array<string,mixed> */
function fixtureRow(PDO $pdo, string $sql, array $parameters): array
{
    $rows = fixtureRows($pdo, $sql, $parameters);
    if (count($rows) !== 1) {
        throw new RuntimeException('fixture_state_invalid');
    }

    return $rows[0];
}

/** @param list<mixed> $parameters @return list<array<string,mixed>> */
function fixtureRows(PDO $pdo, string $sql, array $parameters): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<int> */
function fixtureMediaJobIds(PDO $pdo): array
{
    return array_map(
        static fn (mixed $id): int => (int) $id,
        $pdo->query("SELECT id FROM processing_jobs WHERE queue_name = 'media' ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN)
    );
}

/** @param array{files:list<string>,directories:list<string>} $tree */
function fixtureTemporaryCount(array $tree): int
{
    $count = 0;
    foreach ($tree['files'] as $path) {
        $name = basename($path);
        if (str_starts_with($name, 'clipforge-process-')
            || str_starts_with($name, 'clipforge-video-')
            || str_starts_with($name, 'clipforge-thumbnail-')
            || str_ends_with($name, '.download.part')) {
            ++$count;
        }
    }

    return $count;
}

/** @param array{files:list<string>,directories:list<string>} $tree */
function fixtureRemoveDirectory(array $tree): void
{
    foreach ($tree['files'] as $path) {
        if (!unlink($path)) {
            throw new RuntimeException('fixture_cleanup_failed');
        }
    }
    foreach ($tree['directories'] as $path) {
        if (!rmdir($path)) {
            throw new RuntimeException('fixture_cleanup_failed');
        }
    }
}

/** @return array{files:list<string>,directories:list<string>} */
function fixtureSafeTree(string $root): array
{
    if (!file_exists($root) && !is_link($root)) {
        return ['files' => [], 'directories' => []];
    }
    $canonicalRoot = realpath($root);
    if ($canonicalRoot === false || fixtureIsReparse($root) || !is_dir($root)
        || fixtureComparablePath($canonicalRoot) !== fixtureComparablePath($root)) {
        throw new RuntimeException('fixture_cleanup_failed');
    }
    $files = [];
    $directories = [];
    $visit = function (string $directory) use (&$visit, &$files, &$directories, $canonicalRoot): void {
        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new RuntimeException('fixture_cleanup_failed');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            $stat = lstat($path);
            if ($stat === false || fixtureIsReparse($path)) {
                throw new RuntimeException('fixture_cleanup_failed');
            }
            $canonical = realpath($path);
            if ($canonical === false || !fixturePathWithin($canonical, $canonicalRoot)
                || fixtureComparablePath($canonical) !== fixtureComparablePath($path)) {
                throw new RuntimeException('fixture_cleanup_failed');
            }
            $type = ((int) ($stat['mode'] ?? 0)) & 0xF000;
            if ($type === 0x4000) {
                $visit($path);
                $directories[] = $path;
            } elseif ($type === 0x8000) {
                $files[] = $path;
            } else {
                throw new RuntimeException('fixture_cleanup_failed');
            }
        }
    };
    $visit($canonicalRoot);
    $directories[] = $canonicalRoot;

    return ['files' => $files, 'directories' => $directories];
}
