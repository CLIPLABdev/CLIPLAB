<?php

declare(strict_types=1);

use App\Core\Migrator;
use Tests\Support\SafePhase5TestDatabase;

$projectRoot = dirname(__DIR__, 3);
require $projectRoot . '/vendor/autoload.php';

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
        throw new RuntimeException('invalid_invocation');
    }
    $action = is_string($argv[1] ?? null) ? $argv[1] : '';
    $runId = is_string($argv[2] ?? null) ? $argv[2] : '';
    if (!in_array($action, ['prepare', 'mutate', 'audit', 'teardown'], true)
        || preg_match('/\Asr_[a-f0-9]{32}\z/D', $runId) !== 1) {
        throw new RuntimeException('invalid_invocation');
    }

    $pdo = safetyDatabase();
    if ($action === 'prepare') {
        (new Migrator($pdo, $projectRoot . '/database/migrations'))->run();
    }
    $identity = safetyIdentity($runId);
    $result = match ($action) {
        'prepare' => safetyPrepare($pdo, $identity),
        'mutate' => safetyMutate($pdo, $identity),
        'audit' => safetyAudit($pdo, $identity),
        'teardown' => safetyTeardown($pdo, $identity),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Smart reframe fixture safety helper failed.\n");
    exit(1);
}

/** @return array{owner_email:string,foreign_email:string,ingest_key:string,guard_plan_slug:string,guard_email:string,guard_ingest_key:string,rate_key:string} */
function safetyIdentity(string $runId): array
{
    $hex = substr($runId, 3);
    $ownerEmail = 'sr-owner+' . $hex . '@clipforge.test';

    return [
        'owner_email' => $ownerEmail,
        'foreign_email' => 'sr-foreign+' . $hex . '@clipforge.test',
        'ingest_key' => hash('sha256', 'smart-reframe-e2e-' . $runId),
        'guard_plan_slug' => 'sr-guard-' . $hex,
        'guard_email' => 'sr-guard+' . $hex . '@clipforge.test',
        'guard_ingest_key' => hash('sha256', 'smart-reframe-guard-' . $runId),
        'rate_key' => hash('sha256', '127.0.0.1|' . $ownerEmail),
    ];
}

function safetyDatabase(): PDO
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
 * @param array{owner_email:string,foreign_email:string,ingest_key:string,guard_plan_slug:string,guard_email:string,guard_ingest_key:string,rate_key:string} $identity
 * @return array{guard_project_id:int}
 */
function safetyPrepare(PDO $pdo, array $identity): array
{
    if (safetyScalar($pdo, 'SELECT COUNT(*) FROM plans WHERE slug = ?', [$identity['guard_plan_slug']]) !== 0
        || safetyScalar($pdo, 'SELECT COUNT(*) FROM users WHERE email IN (?, ?, ?)', [
            $identity['owner_email'], $identity['foreign_email'], $identity['guard_email'],
        ]) !== 0
        || safetyScalar($pdo, 'SELECT COUNT(*) FROM projects WHERE ingest_key IN (?, ?)', [
            $identity['ingest_key'], $identity['guard_ingest_key'],
        ]) !== 0
        || safetyScalar($pdo, 'SELECT COUNT(*) FROM rate_limits WHERE action = ? AND rate_key = ?', [
            'login-identity', $identity['rate_key'],
        ]) !== 0) {
        throw new RuntimeException('fixture_not_isolated');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO plans (slug, name, credits, features) VALUES (?, ?, 0, JSON_OBJECT())'
        )->execute([$identity['guard_plan_slug'], 'Smart reframe guard ' . substr($identity['guard_plan_slug'], -8)]);
        $planId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 0)'
        )->execute(['Smart reframe guard', $identity['guard_email'], password_hash('Guard#fixture', PASSWORD_DEFAULT), $planId]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO projects (user_id, ingest_key, name, status, progress) "
            . "VALUES (?, ?, 'Smart reframe guard', 'draft', 0)"
        )->execute([$userId, $identity['guard_ingest_key']]);
        $guardProjectId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO rate_limits (rate_key, action, window_started_at, attempts, expires_at) '
            . "VALUES (?, 'login-identity', UTC_TIMESTAMP(), 7, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))"
        )->execute([$identity['rate_key']]);
        $pdo->commit();

        return ['guard_project_id' => $guardProjectId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @param array{owner_email:string,foreign_email:string,ingest_key:string,guard_plan_slug:string,guard_email:string,guard_ingest_key:string,rate_key:string} $identity
 * @return array{rate_attempts:int}
 */
function safetyMutate(PDO $pdo, array $identity): array
{
    $statement = $pdo->prepare(
        "UPDATE rate_limits SET attempts = 13 WHERE action = 'login-identity' AND rate_key = ?"
    );
    $statement->execute([$identity['rate_key']]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('fixture_state_invalid');
    }

    return ['rate_attempts' => 13];
}

/**
 * @param array{owner_email:string,foreign_email:string,ingest_key:string,guard_plan_slug:string,guard_email:string,guard_ingest_key:string,rate_key:string} $identity
 * @return array{fixture_projects:int,fixture_users:int,fixture_plans:int,guard_projects:int,rate_rows:int,rate_attempts:int|null}
 */
function safetyAudit(PDO $pdo, array $identity): array
{
    $statement = $pdo->prepare(
        "SELECT attempts FROM rate_limits WHERE action = 'login-identity' AND rate_key = ?"
    );
    $statement->execute([$identity['rate_key']]);
    $attempts = $statement->fetchColumn();

    return [
        'fixture_projects' => safetyScalar(
            $pdo,
            'SELECT COUNT(*) FROM projects p INNER JOIN users u ON u.id = p.user_id '
                . 'WHERE p.ingest_key = ? AND u.email = ?',
            [$identity['ingest_key'], $identity['owner_email']]
        ),
        'fixture_users' => safetyScalar(
            $pdo,
            'SELECT COUNT(*) FROM users WHERE email IN (?, ?)',
            [$identity['owner_email'], $identity['foreign_email']]
        ),
        'fixture_plans' => safetyScalar(
            $pdo,
            'SELECT COUNT(*) FROM plans WHERE slug = ?',
            ['sr-' . substr($identity['owner_email'], strlen('sr-owner+'), 32)]
        ),
        'guard_projects' => safetyScalar(
            $pdo,
            'SELECT COUNT(*) FROM projects p INNER JOIN users u ON u.id = p.user_id '
                . 'WHERE p.ingest_key = ? AND u.email = ?',
            [$identity['guard_ingest_key'], $identity['guard_email']]
        ),
        'rate_rows' => safetyScalar(
            $pdo,
            "SELECT COUNT(*) FROM rate_limits WHERE action = 'login-identity' AND rate_key = ?",
            [$identity['rate_key']]
        ),
        'rate_attempts' => $attempts === false ? null : (int) $attempts,
    ];
}

/**
 * @param array{owner_email:string,foreign_email:string,ingest_key:string,guard_plan_slug:string,guard_email:string,guard_ingest_key:string,rate_key:string} $identity
 * @return array{ok:bool}
 */
function safetyTeardown(PDO $pdo, array $identity): array
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE p FROM projects p INNER JOIN users u ON u.id = p.user_id '
            . 'WHERE p.ingest_key = ? AND u.email = ?'
        )->execute([$identity['guard_ingest_key'], $identity['guard_email']]);
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$identity['guard_email']]);
        $pdo->prepare('DELETE FROM plans WHERE slug = ?')->execute([$identity['guard_plan_slug']]);
        $pdo->prepare("DELETE FROM rate_limits WHERE action = 'login-identity' AND rate_key = ?")
            ->execute([$identity['rate_key']]);
        $pdo->commit();

        return ['ok' => true];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @param list<mixed> $parameters */
function safetyScalar(PDO $pdo, string $sql, array $parameters): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}
