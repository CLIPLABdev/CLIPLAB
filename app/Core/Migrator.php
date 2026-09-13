<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Migrator
{
    private const PARTIAL_RECOVERY_MIGRATION = '202609030002_create_project_processing_tables.sql';
    private const CLEANUP_RESERVATION_MIGRATION = '202609040003_upgrade_render_artifact_cleanup_reservations.sql';
    private const DIMENSIONS_MIGRATION = '202609060044_upgrade_render_profile_dimensions.sql';
    private const DIMENSIONS_CHECK = "(aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL) OR (reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND ((aspect_ratio = '9:16' AND ((output_width = 720 AND output_height = 1280) OR (output_width = 1080 AND output_height = 1920))) OR (aspect_ratio = '1:1' AND ((output_width = 720 AND output_height = 720) OR (output_width = 1080 AND output_height = 1080))) OR (aspect_ratio = '16:9' AND ((output_width = 1280 AND output_height = 720) OR (output_width = 1920 AND output_height = 1080))) OR (aspect_ratio = '4:5' AND ((output_width = 720 AND output_height = 900) OR (output_width = 1080 AND output_height = 1350)))))";

    /** @var array<string, array<string, mixed>> */
    private const CREATE_TABLE_POSTCONDITIONS = [
        '202609040004_create_clip_render_profiles.sql' => [
            'table' => 'clip_render_profiles',
            'columns' => [
                'id' => ['bigint', true, false, null, null],
                'clip_id' => ['bigint', true, false, null, null],
                'render_revision' => ['int', true, false, null, null],
                'aspect_ratio' => ['enum', false, false, null, null],
                'reframe_mode' => ['enum', false, false, null, null],
                'output_width' => ['smallint', true, true, null, null],
                'output_height' => ['smallint', true, true, null, null],
                'detector_version' => ['varchar', false, true, 64, null],
                'created_at' => ['timestamp', false, false, null, null],
            ],
            'enums' => [
                'aspect_ratio' => "enum('original','9:16','1:1','16:9','4:5')",
                'reframe_mode' => "enum('original','center','manual','auto')",
            ],
            'indexes' => [
                'PRIMARY' => [['id'], false],
                'uq_clip_render_profiles_revision' => [['clip_id', 'render_revision'], false],
                'idx_clip_render_profiles_clip_created' => [['clip_id', 'created_at'], true],
            ],
            'auto_increment' => ['id'],
            'foreign_keys' => [
                'fk_clip_render_profiles_clip' => ['clip_id', 'clips', 'id', 'CASCADE'],
            ],
            'checks' => [
                'chk_clip_render_profiles_revision' => 'render_revision > 0',
                'chk_clip_render_profiles_shape' => "(aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL) OR (aspect_ratio = '9:16' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 1280) OR (aspect_ratio = '1:1' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 720) OR (aspect_ratio = '16:9' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 1280 AND output_height = 720) OR (aspect_ratio = '4:5' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 900)",
                'chk_clip_render_profiles_detector' => "(reframe_mode = 'auto' AND detector_version IS NOT NULL AND detector_version = 'tasks-vision-1.0.1/blazeface-short-f16-r1') OR (reframe_mode <> 'auto' AND detector_version IS NULL)",
            ],
        ],
        '202609040005_create_clip_reframe_keyframes.sql' => [
            'table' => 'clip_reframe_keyframes',
            'columns' => [
                'id' => ['bigint', true, false, null, null],
                'render_profile_id' => ['bigint', true, false, null, null],
                'sequence_index' => ['tinyint', true, false, null, null],
                'at_ms' => ['int', true, false, null, null],
                'center_x' => ['decimal', true, false, null, 6],
                'center_y' => ['decimal', true, false, null, 6],
                'source' => ['enum', false, false, null, null],
                'created_at' => ['timestamp', false, false, null, null],
            ],
            'enums' => ['source' => "enum('manual','detected')"],
            'precisions' => ['center_x' => 7, 'center_y' => 7],
            'indexes' => [
                'PRIMARY' => [['id'], false],
                'uq_clip_reframe_keyframes_sequence' => [['render_profile_id', 'sequence_index'], false],
                'uq_clip_reframe_keyframes_time' => [['render_profile_id', 'at_ms'], false],
            ],
            'auto_increment' => ['id'],
            'foreign_keys' => [
                'fk_clip_reframe_keyframes_profile' => ['render_profile_id', 'clip_render_profiles', 'id', 'CASCADE'],
            ],
            'checks' => [
                'chk_clip_reframe_keyframes_sequence' => 'sequence_index <= 31',
                'chk_clip_reframe_keyframes_time' => 'at_ms <= 180000',
                'chk_clip_reframe_keyframes_center_x' => 'center_x <= 1',
                'chk_clip_reframe_keyframes_center_y' => 'center_y <= 1',
            ],
        ],
        '202609040006_create_user_consents.sql' => [
            'table' => 'user_consents',
            'columns' => [
                'id' => ['bigint', true, false, null, null],
                'user_id' => ['bigint', true, false, null, null],
                'purpose' => ['varchar', false, false, 64, null],
                'policy_version' => ['varchar', false, false, 32, null],
                'granted_at' => ['datetime', false, false, null, null],
                'revoked_at' => ['datetime', false, true, null, null],
            ],
            'enums' => [],
            'indexes' => [
                'PRIMARY' => [['id'], false],
                'uq_user_consents_version' => [['user_id', 'purpose', 'policy_version'], false],
                'idx_user_consents_active' => [['user_id', 'purpose', 'policy_version', 'revoked_at'], true],
            ],
            'auto_increment' => ['id'],
            'foreign_keys' => [
                'fk_user_consents_user' => ['user_id', 'users', 'id', 'CASCADE'],
            ],
            'checks' => [
                'chk_user_consents_dates' => 'revoked_at IS NULL OR revoked_at >= granted_at',
            ],
        ],
    ];

    /** @var array<string, array{kind: string, name: string}> */
    private const RECOVERABLE_STATEMENTS = [
        'ALTER TABLE projects ADD COLUMN ingest_key CHAR(64) NULL AFTER user_id' => ['kind' => 'column', 'name' => 'ingest_key'],
        'ALTER TABLE projects ADD COLUMN progress TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (progress <= 100) AFTER status' => ['kind' => 'column', 'name' => 'progress'],
        'ALTER TABLE projects ADD COLUMN error_code VARCHAR(64) NULL AFTER progress' => ['kind' => 'column', 'name' => 'error_code'],
        'ALTER TABLE projects ADD COLUMN error_message VARCHAR(255) NULL AFTER error_code' => ['kind' => 'column', 'name' => 'error_message'],
        'ALTER TABLE projects ADD UNIQUE INDEX uq_projects_user_ingest (user_id, ingest_key)' => ['kind' => 'index', 'name' => 'uq_projects_user_ingest'],
    ];

    /** @var array<string, string> */
    private const CLEANUP_RESERVATION_STATEMENTS = [
        'ALTER TABLE render_artifact_cleanups ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER object_key' => 'job_id',
        'ALTER TABLE render_artifact_cleanups ADD COLUMN lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER job_id' => 'lease_token_hash',
        'ALTER TABLE render_artifact_cleanups ADD COLUMN cleanup_after DATETIME NULL AFTER lease_token_hash' => 'cleanup_after',
    ];

    public function __construct(
        private PDO $pdo,
        private string $directory
    ) {
    }

    /** @return list<string> */
    public function run(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (migration VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)'
        );

        $applied = $this->pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $executed = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, $applied, true)) {
                continue;
            }

            $this->pdo->beginTransaction();

            try {
                $this->executeStatements((string) file_get_contents($file), $name);
                $this->verifyKnownCreateTable($name);
                $statement = $this->pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
                $statement->execute(['migration' => $name]);

                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                $executed[] = $name;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $exception;
            }
        }

        return $executed;
    }

    private function executeStatements(string $sql, string $migration): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
        $serverVersion = $migration === self::DIMENSIONS_MIGRATION
            ? (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION) : '';

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            try {
                $this->pdo->exec(self::statementForServer($migration, $statement, $serverVersion));
            } catch (PDOException $exception) {
                if (!$this->isRecoverableDuplicate($migration, $statement, $exception)) {
                    throw $exception;
                }
            }
        }
    }

    /** Translate only the known dimension upgrade; applied SQL files remain immutable. */
    public static function statementForServer(string $migration, string $statement, string $serverVersion): string
    {
        if ($migration !== self::DIMENSIONS_MIGRATION
            || stripos($serverVersion, 'mariadb') !== false
            || preg_match('/^(?:8|9)\./', $serverVersion) !== 1
            || preg_match('/\AALTER TABLE clip_render_profiles\s+DROP CONSTRAINT chk_clip_render_profiles_shape,\s+ADD CONSTRAINT chk_clip_render_profiles_shape CHECK\s*\((.*)\)\s*\z/s', trim($statement), $match) !== 1
            || !self::checkConstraintMetadataMatches($match[1], self::DIMENSIONS_CHECK, null)
        ) {
            return $statement;
        }

        return str_replace('DROP CONSTRAINT chk_clip_render_profiles_shape', 'DROP CHECK chk_clip_render_profiles_shape', $statement);
    }

    private function isRecoverableDuplicate(string $migration, string $statement, PDOException $exception): bool
    {
        $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
        $normalized = preg_replace('/\s+/', ' ', trim($statement));
        if (!is_string($normalized)) {
            return false;
        }

        if ((new SaasMigrationSchema($this->pdo))->isRecoverableDuplicate($migration, $normalized, $driverCode)) {
            return true;
        }

        if ($migration === self::CLEANUP_RESERVATION_MIGRATION) {
            return $driverCode === 1060
                && isset(self::CLEANUP_RESERVATION_STATEMENTS[$normalized])
                && $this->cleanupReservationColumnMatches(self::CLEANUP_RESERVATION_STATEMENTS[$normalized]);
        }
        if ($migration !== self::PARTIAL_RECOVERY_MIGRATION
            || !isset(self::RECOVERABLE_STATEMENTS[$normalized])
        ) {
            return false;
        }

        $expected = self::RECOVERABLE_STATEMENTS[$normalized];
        if ($driverCode === 1060 && $expected['kind'] === 'column') {
            return $this->columnMatches($expected['name']);
        }
        if ($driverCode === 1061 && $expected['kind'] === 'index') {
            return $this->indexMatches($expected['name']);
        }

        return false;
    }

    private function verifyKnownCreateTable(string $migration): void
    {
        (new SaasMigrationSchema($this->pdo))->verifyMigration($migration);

        $expected = self::CREATE_TABLE_POSTCONDITIONS[
            $migration === self::DIMENSIONS_MIGRATION ? '202609040004_create_clip_render_profiles.sql' : $migration
        ] ?? null;
        if (!is_array($expected)) {
            return;
        }

        try {
            $table = (string) $expected['table'];
            if ($migration === self::DIMENSIONS_MIGRATION
                || ($table === 'clip_render_profiles' && $this->checksMatch($table, [
                    'chk_clip_render_profiles_shape' => self::DIMENSIONS_CHECK,
                ]))
            ) {
                // A surviving table may already have the exact additive successor constraint.
                $expected['checks']['chk_clip_render_profiles_shape'] = self::DIMENSIONS_CHECK;
            }
            if (!$this->columnsMatch(
                $table,
                $expected['columns'],
                $expected['enums'],
                $expected['precisions'] ?? [],
                $expected['auto_increment']
            )
                || !$this->indexesMatch($table, $expected['indexes'])
                || !$this->foreignKeysMatch($table, $expected['foreign_keys'])
                || !$this->checksMatch($table, $expected['checks'])
            ) {
                throw new RuntimeException('Migration schema postcondition failed.');
            }
        } catch (PDOException) {
            throw new RuntimeException('Migration schema postcondition failed.');
        }
    }

    /** @param array<string, array{string,bool,bool,int|null,int|null}> $expected @param array<string,string> $enums @param array<string,int> $precisions @param list<string> $autoIncrement */
    private function columnsMatch(string $table, array $expected, array $enums, array $precisions, array $autoIncrement): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, EXTRA '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['COLUMN_NAME']] = $row;
        }
        foreach ($expected as $name => [$type, $unsigned, $nullable, $length, $scale]) {
            $row = $rows[$name] ?? null;
            if (!is_array($row)
                || strtolower((string) $row['DATA_TYPE']) !== $type
                || str_contains(strtolower((string) $row['COLUMN_TYPE']), 'unsigned') !== $unsigned
                || ((string) $row['IS_NULLABLE'] === 'YES') !== $nullable
                || ($length !== null && (int) $row['CHARACTER_MAXIMUM_LENGTH'] !== $length)
                || (isset($precisions[$name]) && (int) $row['NUMERIC_PRECISION'] !== $precisions[$name])
                || ($scale !== null && (int) $row['NUMERIC_SCALE'] !== $scale)
                || (isset($enums[$name]) && strtolower(str_replace(' ', '', (string) $row['COLUMN_TYPE'])) !== $enums[$name])
                || (in_array($name, $autoIncrement, true)
                    && !in_array('auto_increment', preg_split('/\s+/', strtolower(trim((string) $row['EXTRA']))) ?: [], true))
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, array{list<string>,bool}> $expected */
    private function indexesMatch(string $table, array $expected): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute([$table]);
        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[(string) $row['INDEX_NAME']][] = [
                (string) $row['COLUMN_NAME'],
                (int) $row['NON_UNIQUE'],
                $row['SUB_PART'] === null ? null : (int) $row['SUB_PART'],
            ];
        }
        foreach ($expected as $name => [$columns, $nonUnique]) {
            $rows = $indexes[$name] ?? [];
            if (array_column($rows, 0) !== $columns
                || array_unique(array_column($rows, 1)) !== [(int) $nonUnique]
                || array_unique(array_column($rows, 2), SORT_REGULAR) !== [null]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, array{string,string,string,string}> $expected */
    private function foreignKeysMatch(string $table, array $expected): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT tc.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE '
            . 'FROM information_schema.TABLE_CONSTRAINTS tc '
            . 'INNER JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND k.TABLE_NAME = tc.TABLE_NAME '
            . 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . "WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $statement->execute([$table]);
        $foreignKeys = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $foreignKeys[(string) $row['CONSTRAINT_NAME']] = [
                (string) $row['COLUMN_NAME'],
                (string) $row['REFERENCED_TABLE_NAME'],
                (string) $row['REFERENCED_COLUMN_NAME'],
                (string) $row['DELETE_RULE'],
            ];
        }

        foreach ($expected as $name => $definition) {
            if (($foreignKeys[$name] ?? null) !== $definition) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,string> $expected */
    private function checksMatch(string $table, array $expected): bool
    {
        $enforcedColumn = $this->hasCheckEnforcementMetadata() ? ', tc.ENFORCED' : ', NULL AS ENFORCED';
        $statement = $this->pdo->prepare(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE' . $enforcedColumn . ' FROM information_schema.TABLE_CONSTRAINTS tc '
            . 'INNER JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . "WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'CHECK'"
        );
        $statement->execute([$table]);
        $checks = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $checks[(string) $row['CONSTRAINT_NAME']] = [
                'clause' => (string) $row['CHECK_CLAUSE'],
                'enforced' => $row['ENFORCED'] === null ? null : (string) $row['ENFORCED'],
            ];
        }
        foreach ($expected as $name => $clause) {
            $actual = $checks[$name] ?? null;
            if (!is_array($actual)
                || !self::checkConstraintMetadataMatches($actual['clause'], $clause, $actual['enforced'])
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasCheckEnforcementMetadata(): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'information_schema' "
            . "AND TABLE_NAME = 'TABLE_CONSTRAINTS' AND COLUMN_NAME = 'ENFORCED'"
        );
        $statement->execute();

        return (int) $statement->fetchColumn() === 1;
    }

    /** @internal Executable seam for engine-specific CHECK metadata. */
    public static function checkConstraintMetadataMatches(
        string $actualClause,
        string $expectedClause,
        ?string $enforced
    ): bool {
        if ($enforced !== null && strtoupper(trim($enforced)) !== 'YES') {
            return false;
        }

        $actual = self::canonicalCheck($actualClause);
        $expected = self::canonicalCheck($expectedClause);
        if ($actual !== null && $actual === $expected) {
            return true;
        }

        return self::mysql84DimensionShapeEquivalent($actualClause, $expectedClause);
    }

    private static function mysql84DimensionShapeEquivalent(string $actualClause, string $expectedClause): bool
    {
        $expected = self::normalizeShapeClause($expectedClause);
        if ($expected === null || !str_contains($expected, 'aspect_ratio=original')) {
            return false;
        }

        $actual = self::normalizeShapeClause($actualClause);
        if ($actual === null) {
            return false;
        }

        foreach ([
            'aspect_ratio=original',
            'reframe_mode=original',
            'output_widthisnull',
            'output_heightisnull',
            'reframe_modeincentermanualauto',
            'output_widthisnotnull',
            'output_heightisnotnull',
            'aspect_ratio=9:16',
            'output_width=720',
            'output_height=1280',
            'output_width=1080',
            'output_height=1920',
            'aspect_ratio=1:1',
            'output_width=720',
            'output_height=720',
            'output_width=1080',
            'output_height=1080',
            'aspect_ratio=16:9',
            'output_width=1280',
            'output_height=720',
            'output_width=1920',
            'output_height=1080',
            'aspect_ratio=4:5',
            'output_width=720',
            'output_height=900',
            'output_width=1080',
            'output_height=1350',
        ] as $token) {
            $hasInActual = str_contains($actual, $token);
            $hasInExpected = str_contains($expected, $token);
            if ($hasInActual !== $hasInExpected) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeShapeClause(string $clause): ?string
    {
        $normalized = trim($clause);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(["\\'", '\\"'], ["'", '"'], $normalized);
        $normalized = preg_replace('/`([^`]+)`/', '$1', $normalized);
        $normalized = preg_replace("/(?<![A-Za-z0-9_])(?:_utf8mb4|_utf8mb3|_utf8|_binary)(?=(?:\\\\)?['\"])/i", '', $normalized);
        $normalized = preg_replace('/\s+/', '', strtolower($normalized));
        $normalized = str_replace(["'", '"', '(', ')', ',', ';'], '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    private static function canonicalCheck(string $clause): ?string
    {
        $clause = trim($clause);
        $clause = str_replace(["\\'", '\\"'], ["'", '"'], $clause);
        $clause = preg_replace("/(?<![A-Za-z0-9_])(?:_utf8mb4|_utf8mb3|_utf8|_binary)(?=(?:\\\\)?['\"])/i", '', $clause);
        $clause = preg_replace("/\b_[a-z0-9]+\s*(?=(?:\\\\)?['\"])/i", '', $clause);
        if (!is_string($clause) || $clause === '') {
            return null;
        }
        $tokens = [];
        $offset = 0;
        $length = strlen($clause);
        $pattern = "/\\G\\s*('(?:''|[^'])*'|`(?:``|[^`])*`|<>|<=|>=|=|<|>|\\(|\\)|,|[a-z_][a-z0-9_:\\/.-]*|[0-9]+(?:\\.[0-9]+)?)/Ai";
        while ($offset < $length) {
            if (preg_match($pattern, $clause, $match, 0, $offset) !== 1) {
                return null;
            }
            $token = $match[1];
            if ($token[0] === '`') {
                $token = str_replace('``', '`', substr($token, 1, -1));
            }
            $tokens[] = $token[0] === "'" ? $token : strtolower($token);
            $offset += strlen($match[0]);
        }

        return self::canonicalBooleanTokens($tokens);
    }

    private static function canonicalBooleanTokens(array $tokens): ?string
    {
        $tokens = self::stripOuterParentheses($tokens);
        if ($tokens === []) {
            return null;
        }
        foreach (['or', 'and'] as $operator) {
            $parts = self::splitTopLevelOperator($tokens, $operator);
            if (count($parts) > 1) {
                $children = [];
                foreach ($parts as $part) {
                    $child = self::canonicalBooleanTokens($part);
                    if ($child === null) {
                        return null;
                    }
                    $prefix = $operator . '(';
                    if (str_starts_with($child, $prefix) && substr($child, -1) === ')') {
                        $children[] = substr($child, strlen($prefix), -1);
                    } else {
                        $children[] = $child;
                    }
                }

                return $operator . '(' . implode(',', $children) . ')';
            }
        }

        return 'atom:' . implode('', $tokens);
    }

    /** @param list<string> $tokens @return list<string> */
    private static function stripOuterParentheses(array $tokens): array
    {
        while (count($tokens) >= 2 && $tokens[0] === '(' && $tokens[count($tokens) - 1] === ')') {
            $depth = 0;
            $wrapsAll = true;
            foreach ($tokens as $index => $token) {
                $depth += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
                if ($depth < 0 || ($depth === 0 && $index < count($tokens) - 1)) {
                    $wrapsAll = false;
                    break;
                }
            }
            if (!$wrapsAll || $depth !== 0) {
                break;
            }
            $tokens = array_slice($tokens, 1, -1);
        }

        return array_values($tokens);
    }

    /** @param list<string> $tokens @return list<list<string>> */
    private static function splitTopLevelOperator(array $tokens, string $operator): array
    {
        $parts = [];
        $part = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth < 0) {
                    return [$tokens];
                }
            }
            if ($depth === 0 && $token === $operator) {
                if ($part === []) {
                    return [$tokens];
                }
                $parts[] = $part;
                $part = [];
                continue;
            }
            $part[] = $token;
        }
        if ($depth !== 0 || $part === []) {
            return [$tokens];
        }
        $parts[] = $part;

        return $parts;
    }

    private function cleanupReservationColumnMatches(string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, '
            . 'IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(['render_artifact_cleanups', $column]);
        $definition = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($definition) || $definition['IS_NULLABLE'] !== 'YES'
            || !$this->isNullDefault($definition['COLUMN_DEFAULT'])
        ) {
            return false;
        }
        if ($column === 'job_id') {
            return $definition['DATA_TYPE'] === 'bigint'
                && str_contains(strtolower((string) $definition['COLUMN_TYPE']), 'unsigned');
        }
        if ($column === 'lease_token_hash') {
            return $definition['DATA_TYPE'] === 'char'
                && (int) $definition['CHARACTER_MAXIMUM_LENGTH'] === 64
                && strtolower((string) $definition['CHARACTER_SET_NAME']) === 'ascii'
                && strtolower((string) $definition['COLLATION_NAME']) === 'ascii_bin';
        }

        return $column === 'cleanup_after' && $definition['DATA_TYPE'] === 'datetime';
    }

    private function columnMatches(string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(['projects', $column]);
        $definition = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($definition)) {
            return false;
        }

        if ($column === 'ingest_key') {
            return $definition['DATA_TYPE'] === 'char'
                && (int) $definition['CHARACTER_MAXIMUM_LENGTH'] === 64
                && $definition['IS_NULLABLE'] === 'YES'
                && $this->isNullDefault($definition['COLUMN_DEFAULT']);
        }
        if ($column === 'progress') {
            return $definition['DATA_TYPE'] === 'tinyint'
                && str_contains(strtolower((string) $definition['COLUMN_TYPE']), 'unsigned')
                && $definition['IS_NULLABLE'] === 'NO'
                && (int) $definition['COLUMN_DEFAULT'] === 0
                && $this->hasProgressCheck();
        }

        $length = $column === 'error_code' ? 64 : 255;

        return in_array($column, ['error_code', 'error_message'], true)
            && $definition['DATA_TYPE'] === 'varchar'
            && (int) $definition['CHARACTER_MAXIMUM_LENGTH'] === $length
            && $definition['IS_NULLABLE'] === 'YES'
            && $this->isNullDefault($definition['COLUMN_DEFAULT']);
    }

    private function isNullDefault(mixed $default): bool
    {
        return $default === null || $default === 'NULL';
    }

    private function hasProgressCheck(): bool
    {
        try {
            $statement = $this->pdo->query(
                "SELECT cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc "
                . "INNER JOIN information_schema.TABLE_CONSTRAINTS tc "
                . "ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME "
                . "WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'projects' AND tc.CONSTRAINT_TYPE = 'CHECK'"
            );
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $clause) {
                $normalized = str_replace(['`', '(', ')', ' '], '', strtolower((string) $clause));
                if ($normalized === 'progress<=100') {
                    return true;
                }
            }
        } catch (PDOException) {
            return false;
        }

        return false;
    }

    private function indexMatches(string $index): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX'
        );
        $statement->execute(['projects', $index]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, 'COLUMN_NAME') === ['user_id', 'ingest_key']
            && array_unique(array_map('intval', array_column($rows, 'NON_UNIQUE'))) === [0];
    }
}
