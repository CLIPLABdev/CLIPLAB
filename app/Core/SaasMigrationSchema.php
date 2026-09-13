<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class SaasMigrationSchema
{
    private const EDITOR = '202609060001_create_clip_editor_profiles.sql';
    private const TRACKS = '202609060002_create_clip_subtitle_tracks.sql';
    private const CUES = '202609060003_create_clip_subtitle_cues.sql';
    private const AUTO_EXPORT = '202609060004_add_project_automatic_exports.sql';
    private const USAGE = '202609060010_add_project_usage_recorded_at.sql';
    private const SOURCE_CLEANUPS = '202609060030_create_source_artifact_cleanups.sql';

    /** @var array<string, array<string, mixed>> */
    private const TABLES = [
        self::EDITOR => [
            'table' => 'clip_editor_profiles',
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false, 'auto_increment' => true],
                'clip_id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false],
                'render_revision' => ['type' => 'int', 'unsigned' => true, 'nullable' => false],
                'parent_clip_id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => true],
                'user_id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false],
                'request_key' => [
                    'type' => 'char', 'nullable' => false, 'length' => 64,
                    'charset' => 'ascii', 'collation' => 'ascii_bin',
                ],
                'options_json' => ['type' => 'json', 'nullable' => false],
                'transcript_mode' => [
                    'type' => 'enum', 'nullable' => false,
                    'column_type' => "enum('none','manual','auto')",
                ],
                'duration_ms' => ['type' => 'int', 'unsigned' => true, 'nullable' => false],
                'created_at' => [
                    'type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp',
                ],
            ],
            'indexes' => [
                'PRIMARY' => [['id'], true],
                'uq_editor_clip_revision' => [['clip_id', 'render_revision'], true],
                'uq_editor_user_request' => [['user_id', 'request_key'], true],
            ],
            'foreign_keys' => [
                'fk_editor_clip' => ['clip_id', 'clips', 'id', 'CASCADE'],
                'fk_editor_parent' => ['parent_clip_id', 'clips', 'id', 'SET NULL'],
                'fk_editor_user' => ['user_id', 'users', 'id', 'CASCADE'],
            ],
            'checks' => [
                'chk_editor_duration' => 'duration_ms BETWEEN 1000 AND 180000',
            ],
        ],
        self::TRACKS => [
            'table' => 'clip_subtitle_tracks',
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false, 'auto_increment' => true],
                'editor_profile_id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false],
                'language' => ['type' => 'varchar', 'nullable' => false, 'length' => 35, 'default' => 'und'],
                'status' => [
                    'type' => 'enum', 'nullable' => false,
                    'column_type' => "enum('pending','ready','failed')", 'default' => 'pending',
                ],
                'error_code' => ['type' => 'varchar', 'nullable' => true, 'length' => 64, 'default' => null],
                'completed_at' => ['type' => 'datetime', 'nullable' => true, 'default' => null],
                'created_at' => [
                    'type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp',
                ],
            ],
            'indexes' => [
                'PRIMARY' => [['id'], true],
                'uq_subtitle_editor' => [['editor_profile_id'], true],
            ],
            'foreign_keys' => [
                'fk_subtitle_editor' => ['editor_profile_id', 'clip_editor_profiles', 'id', 'CASCADE'],
            ],
            'checks' => [],
        ],
        self::CUES => [
            'table' => 'clip_subtitle_cues',
            'columns' => [
                'id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false, 'auto_increment' => true],
                'track_id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false],
                'cue_index' => ['type' => 'smallint', 'unsigned' => true, 'nullable' => false],
                'start_ms' => ['type' => 'int', 'unsigned' => true, 'nullable' => false],
                'end_ms' => ['type' => 'int', 'unsigned' => true, 'nullable' => false],
                'text' => [
                    'type' => 'varchar', 'nullable' => false, 'length' => 350,
                    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                ],
                'words_json' => ['type' => 'json', 'nullable' => true],
            ],
            'indexes' => [
                'PRIMARY' => [['id'], true],
                'uq_subtitle_cue' => [['track_id', 'cue_index'], true],
            ],
            'foreign_keys' => [
                'fk_cue_track' => ['track_id', 'clip_subtitle_tracks', 'id', 'CASCADE'],
            ],
            'checks' => [
                'chk_cue_interval' => 'start_ms < end_ms AND end_ms <= 180000',
            ],
        ],
        self::SOURCE_CLEANUPS => [
            'table' => 'source_artifact_cleanups',
            'columns' => [
                'object_key' => [
                    'type' => 'varchar', 'nullable' => false, 'length' => 255,
                    'charset' => 'ascii', 'collation' => 'ascii_bin',
                ],
                'job_id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => true, 'default' => null],
                'lease_token_hash' => [
                    'type' => 'char', 'nullable' => true, 'length' => 64, 'default' => null,
                    'charset' => 'ascii', 'collation' => 'ascii_bin',
                ],
                'cleanup_after' => ['type' => 'datetime', 'nullable' => false],
                'created_at' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
            ],
            'indexes' => [
                'PRIMARY' => [['object_key'], true],
                'idx_source_cleanups_due' => [['cleanup_after', 'object_key'], false],
            ],
            'foreign_keys' => [],
            'checks' => [],
        ],
    ];

    private const AUTO_EXPORT_STATEMENT =
        'ALTER TABLE projects ADD COLUMN auto_render_requested TINYINT UNSIGNED NOT NULL DEFAULT 0';
    private const USAGE_STATEMENT =
        'ALTER TABLE projects ADD COLUMN usage_recorded_at DATETIME NULL AFTER processed_duration_seconds, '
        . 'ADD KEY idx_projects_user_usage (user_id, usage_recorded_at)';
    private const SOURCE_CLEANUPS_STATEMENT =
        'ALTER TABLE project_sources ADD INDEX idx_project_sources_object_key (object_key)';

    public function __construct(private PDO $pdo)
    {
    }

    public function verifyMigration(string $migration): void
    {
        $mediaTables = MediaMigrationSchema::tablesForMigration($migration);
        if (!isset(self::TABLES[$migration])
            && $migration !== self::AUTO_EXPORT
            && $migration !== self::USAGE
            && $mediaTables === []
        ) {
            return;
        }

        try {
            if ($mediaTables !== []) {
                $valid = true;
                foreach ($mediaTables as $table) {
                    $valid = $valid && $this->tableMatches($table, true);
                }
            } elseif (isset(self::TABLES[$migration])) {
                $valid = $this->tableMatches(self::TABLES[$migration]);
                if ($migration === self::EDITOR) {
                    $valid = $valid && $this->editorClipDependencyMatches();
                } elseif ($migration === self::SOURCE_CLEANUPS) {
                    $valid = $valid && $this->sourceReferenceIndexMatches();
                }
            } elseif ($migration === self::AUTO_EXPORT) {
                $valid = $this->autoExportMatches();
            } else {
                $valid = $this->usageMatches();
            }
        } catch (PDOException) {
            $valid = false;
        }

        if (!$valid) {
            throw new RuntimeException('Migration schema postcondition failed.');
        }
    }

    public function isRecoverableDuplicate(string $migration, string $statement, int $driverCode): bool
    {
        $target = self::recoveryTarget($migration, $statement, $driverCode);
        if ($target === null) {
            return false;
        }

        try {
            if ($target === 'source_cleanups') {
                return $this->tableMatches(self::TABLES[self::SOURCE_CLEANUPS])
                    && $this->sourceReferenceIndexMatches();
            }

            return $target === 'auto_export' ? $this->autoExportMatches() : $this->usageMatches();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @internal Pure allowlist seam for unit tests. */
    public static function recoveryTarget(string $migration, string $statement, int $driverCode): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($statement));
        if (!is_string($normalized)) {
            return null;
        }
        if ($migration === self::AUTO_EXPORT
            && $driverCode === 1060
            && $normalized === self::AUTO_EXPORT_STATEMENT
        ) {
            return 'auto_export';
        }
        if ($migration === self::USAGE
            && in_array($driverCode, [1060, 1061], true)
            && $normalized === self::USAGE_STATEMENT
        ) {
            return 'usage';
        }
        if ($migration === self::SOURCE_CLEANUPS
            && $driverCode === 1061
            && $normalized === self::SOURCE_CLEANUPS_STATEMENT
        ) {
            return 'source_cleanups';
        }

        return null;
    }

    /** @param array<string,mixed> $expected */
    private function tableMatches(array $expected, bool $requireCompatibleAdditions = false): bool
    {
        $table = (string) $expected['table'];

        return $this->isInnoDbTable($table)
            && $this->columnsMatch($table, $expected['columns'], $requireCompatibleAdditions)
            && $this->indexesMatch($table, $expected['indexes'])
            && $this->foreignKeysMatch($table, $expected['foreign_keys'])
            && $this->checksMatch($table, $expected['checks']);
    }

    private function isInnoDbTable(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);

        return strtolower((string) $statement->fetchColumn()) === 'innodb';
    }

    private function sourceReferenceIndexMatches(): bool
    {
        return $this->indexesMatch('project_sources', [
            'idx_project_sources_object_key' => [['object_key'], false],
        ]);
    }

    private function editorClipDependencyMatches(): bool
    {
        return $this->columnsMatch('clips', [
            'suggestion_index' => ['type' => 'tinyint', 'unsigned' => true, 'nullable' => true],
        ]) && $this->indexesMatch('clips', [
            'uq_clips_analysis_index' => [['ai_analysis_id', 'suggestion_index'], true],
        ]);
    }

    private function autoExportMatches(): bool
    {
        return $this->columnsMatch('projects', [
            'auto_render_requested' => [
                'type' => 'tinyint', 'unsigned' => true, 'nullable' => false, 'default' => 0,
            ],
        ]);
    }

    private function usageMatches(): bool
    {
        return $this->columnsMatch('projects', [
            'usage_recorded_at' => ['type' => 'datetime', 'nullable' => true, 'default' => null],
        ]) && $this->indexesMatch('projects', [
            'idx_projects_user_usage' => [['user_id', 'usage_recorded_at'], false],
        ]);
    }

    /** @param array<string,array<string,mixed>> $expected */
    private function columnsMatch(string $table, array $expected, bool $requireCompatibleAdditions = false): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, '
            . 'CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string) $row['COLUMN_NAME']] = $row;
        }

        foreach ($expected as $name => $rule) {
            $row = $columns[$name] ?? null;
            if (!is_array($row) || !$this->columnMatches($table, $name, $row, $rule)) {
                return false;
            }
        }

        if ($requireCompatibleAdditions) {
            foreach (array_diff_key($columns, $expected) as $row) {
                // Repository INSERTs omit unknown fields; those fields must supply their own value.
                if ((string) $row['IS_NULLABLE'] !== 'YES'
                    && preg_match('/\b(?:auto_increment|(?:virtual|stored|persistent) generated)\b/i', (string) $row['EXTRA']) !== 1
                    && $this->additionalColumnHasNoDefault($row['COLUMN_DEFAULT'])
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function additionalColumnHasNoDefault(mixed $default): bool
    {
        if ($default === null) {
            return true;
        }
        if (strtoupper(trim((string) $default)) !== 'NULL') {
            return false;
        }

        // MySQL returns literal string defaults unquoted; MariaDB quotes literals
        // and reserves the unquoted NULL sentinel for a SQL NULL default.
        return stripos((string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $rule */
    private function columnMatches(string $table, string $name, array $row, array $rule): bool
    {
        $actualType = strtolower((string) $row['DATA_TYPE']);
        $expectedType = (string) $rule['type'];
        if ($expectedType === 'json') {
            if ($actualType !== 'json'
                && ($actualType !== 'longtext' || !$this->hasJsonSemantics($table, $name))
            ) {
                return false;
            }
        } elseif ($actualType !== $expectedType) {
            return false;
        }

        $columnType = strtolower(str_replace(' ', '', (string) $row['COLUMN_TYPE']));
        if (isset($rule['unsigned'])
            && str_contains($columnType, 'unsigned') !== (bool) $rule['unsigned']
        ) {
            return false;
        }
        if (((string) $row['IS_NULLABLE'] === 'YES') !== (bool) $rule['nullable']) {
            return false;
        }
        if (isset($rule['length']) && (int) $row['CHARACTER_MAXIMUM_LENGTH'] !== (int) $rule['length']) {
            return false;
        }
        if (isset($rule['column_type']) && $columnType !== (string) $rule['column_type']) {
            return false;
        }
        if (isset($rule['charset']) && strtolower((string) $row['CHARACTER_SET_NAME']) !== $rule['charset']) {
            return false;
        }
        if (isset($rule['collation']) && strtolower((string) $row['COLLATION_NAME']) !== $rule['collation']) {
            return false;
        }
        if (array_key_exists('default', $rule) && !$this->defaultMatches($row['COLUMN_DEFAULT'], $rule['default'])) {
            return false;
        }
        $extra = str_replace('current_timestamp()', 'current_timestamp', strtolower(trim((string) $row['EXTRA'])));
        $allowedExtras = ($rule['auto_increment'] ?? false) === true ? ['auto_increment'] : [''];
        if (($rule['default'] ?? null) === 'current_timestamp') {
            $allowedExtras[] = 'default_generated';
        }
        if (($rule['on_update'] ?? null) === 'current_timestamp') {
            $allowedExtras = ['on update current_timestamp', 'default_generated on update current_timestamp'];
        }
        if (!in_array($extra, $allowedExtras, true)) {
            return false;
        }

        return true;
    }

    private function defaultMatches(mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null || strtoupper(trim((string) $actual)) === 'NULL';
        }
        $value = trim((string) $actual);
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = str_replace("''", "'", substr($value, 1, -1));
        }
        if ($expected === 'current_timestamp') {
            return in_array(strtolower($value), ['current_timestamp', 'current_timestamp()'], true);
        }
        if (is_int($expected)) {
            return preg_match('/^-?[0-9]+$/D', $value) === 1 && (int) $value === $expected;
        }

        return $value === (string) $expected;
    }

    /** @param array<string,array{list<string>,bool}> $expected */
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

        foreach ($expected as $name => [$columns, $unique]) {
            $rows = $indexes[$name] ?? [];
            if (array_column($rows, 0) !== $columns
                || array_unique(array_column($rows, 1)) !== [$unique ? 0 : 1]
                || array_unique(array_column($rows, 2), SORT_REGULAR) !== [null]
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,array{string,string,string,string}> $expected */
    private function foreignKeysMatch(string $table, array $expected): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT tc.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
            . 'k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE, '
            . '(k.REFERENCED_TABLE_SCHEMA = DATABASE()) AS REFERENCES_CURRENT_SCHEMA '
            . 'FROM information_schema.TABLE_CONSTRAINTS tc '
            . 'INNER JOIN information_schema.KEY_COLUMN_USAGE k '
            . 'ON k.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . 'AND k.TABLE_NAME = tc.TABLE_NAME '
            . 'INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            . 'ON r.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . 'AND r.TABLE_NAME = tc.TABLE_NAME '
            . "WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY' "
            . 'ORDER BY tc.CONSTRAINT_NAME, k.ORDINAL_POSITION'
        );
        $statement->execute([$table]);
        $foreignKeys = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['REFERENCES_CURRENT_SCHEMA'] !== 1
                || !in_array(strtoupper((string) $row['UPDATE_RULE']), ['RESTRICT', 'NO ACTION'], true)
            ) {
                return false;
            }
            $foreignKeys[(string) $row['CONSTRAINT_NAME']][] = [
                (string) $row['COLUMN_NAME'],
                (string) $row['REFERENCED_TABLE_NAME'],
                (string) $row['REFERENCED_COLUMN_NAME'],
                // InnoDB checks both RESTRICT and NO ACTION immediately.
                $row['DELETE_RULE'] === 'NO ACTION' ? 'RESTRICT' : (string) $row['DELETE_RULE'],
            ];
        }

        if (count($foreignKeys) !== count($expected)) {
            return false;
        }
        foreach ($expected as $name => $definition) {
            if (($foreignKeys[$name] ?? null) !== [$definition]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,string> $expected */
    private function checksMatch(string $table, array $expected): bool
    {
        if ($expected === []) {
            return true;
        }
        $checks = $this->checkMetadata($table);
        foreach ($expected as $name => $clause) {
            $actual = $checks[$name] ?? null;
            if (!is_array($actual)
                || !Migrator::checkConstraintMetadataMatches($actual['clause'], $clause, $actual['enforced'])
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasJsonSemantics(string $table, string $column): bool
    {
        foreach ($this->checkMetadata($table) as $check) {
            if (Migrator::checkConstraintMetadataMatches(
                $check['clause'],
                'json_valid(' . $column . ')',
                $check['enforced']
            )) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,array{clause:string,enforced:?string}> */
    private function checkMetadata(string $table): array
    {
        $enforcedColumn = $this->hasCheckEnforcementMetadata() ? ', tc.ENFORCED' : ', NULL AS ENFORCED';
        $tableScope = $this->hasMetadataColumn('CHECK_CONSTRAINTS', 'TABLE_NAME')
            ? 'AND cc.TABLE_NAME = tc.TABLE_NAME ' : '';
        $statement = $this->pdo->prepare(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE' . $enforcedColumn . ' '
            . 'FROM information_schema.TABLE_CONSTRAINTS tc '
            . 'INNER JOIN information_schema.CHECK_CONSTRAINTS cc '
            . 'ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
            . $tableScope
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

        return $checks;
    }

    private function hasCheckEnforcementMetadata(): bool
    {
        return $this->hasMetadataColumn('TABLE_CONSTRAINTS', 'ENFORCED');
    }

    private function hasMetadataColumn(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'information_schema' "
            . 'AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() === 1;
    }
}
