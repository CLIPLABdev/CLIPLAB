<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use App\Core\SaasMigrationSchema;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SaasMigrationSchemaTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/saas-schema-unit-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testMappedCreateMigrationRejectsAnUnverifiableSurvivingTable(): void
    {
        $migration = '202609060003_create_clip_subtitle_cues.sql';
        file_put_contents(
            $this->directory . '/' . $migration,
            'CREATE TABLE IF NOT EXISTS clip_subtitle_cues (id INTEGER PRIMARY KEY);'
        );
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE clip_subtitle_cues (id INTEGER PRIMARY KEY)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration schema postcondition failed.');

        (new Migrator($pdo, $this->directory))->run();
    }

    public function testAlterRecoveryUsesAnExactMigrationStatementAndDriverCodeAllowlist(): void
    {
        $auto = 'ALTER TABLE projects ADD COLUMN auto_render_requested TINYINT UNSIGNED NOT NULL DEFAULT 0';
        $usage = "ALTER TABLE projects\n"
            . "ADD COLUMN usage_recorded_at DATETIME NULL AFTER processed_duration_seconds,\n"
            . 'ADD KEY idx_projects_user_usage (user_id, usage_recorded_at)';

        self::assertSame('auto_export', SaasMigrationSchema::recoveryTarget(
            '202609060004_add_project_automatic_exports.sql',
            $auto,
            1060
        ));
        self::assertSame('usage', SaasMigrationSchema::recoveryTarget(
            '202609060010_add_project_usage_recorded_at.sql',
            $usage,
            1060
        ));
        self::assertSame('usage', SaasMigrationSchema::recoveryTarget(
            '202609060010_add_project_usage_recorded_at.sql',
            $usage,
            1061
        ));
        self::assertNull(SaasMigrationSchema::recoveryTarget(
            '202609060004_add_project_automatic_exports.sql',
            $auto . ' COMMENT \'wrong\'',
            1060
        ));
        self::assertNull(SaasMigrationSchema::recoveryTarget('unmapped.sql', $auto, 1060));
        self::assertNull(SaasMigrationSchema::recoveryTarget(
            '202609060004_add_project_automatic_exports.sql',
            $auto,
            1061
        ));
    }

    public function testMariaDbLongtextRequiresItsOwnEnforcedJsonValidityCheck(): void
    {
        $pdo = $this->metadataPdo();
        $this->seedCueMetadata($pdo, true);

        (new SaasMigrationSchema($pdo))->verifyMigration(
            '202609060003_create_clip_subtitle_cues.sql'
        );
        self::assertTrue(true);

        $pdo = $this->metadataPdo();
        $this->seedCueMetadata($pdo, false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration schema postcondition failed.');
        (new SaasMigrationSchema($pdo))->verifyMigration(
            '202609060003_create_clip_subtitle_cues.sql'
        );
    }

    public function testDuplicateRecoveryAlsoRequiresExactProjectMetadata(): void
    {
        $pdo = $this->metadataPdo();
        $insert = $pdo->prepare(
            'INSERT INTO information_schema.COLUMNS '
            . '(TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute(['unit', 'projects', 'auto_render_requested', 'tinyint', 'tinyint unsigned', 'NO', '0', '']);
        $insert->execute(['unit', 'projects', 'usage_recorded_at', 'datetime', 'datetime', 'YES', null, '']);
        $index = $pdo->prepare(
            'INSERT INTO information_schema.STATISTICS '
            . '(TABLE_SCHEMA, TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART, SEQ_IN_INDEX) '
            . 'VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        $index->execute(['unit', 'projects', 'idx_projects_user_usage', 'user_id', 1, 1]);
        $index->execute(['unit', 'projects', 'idx_projects_user_usage', 'usage_recorded_at', 1, 2]);
        $schema = new SaasMigrationSchema($pdo);
        $auto = 'ALTER TABLE projects ADD COLUMN auto_render_requested TINYINT UNSIGNED NOT NULL DEFAULT 0';
        $usage = 'ALTER TABLE projects ADD COLUMN usage_recorded_at DATETIME NULL AFTER processed_duration_seconds, '
            . 'ADD KEY idx_projects_user_usage (user_id, usage_recorded_at)';

        self::assertTrue($schema->isRecoverableDuplicate(
            '202609060004_add_project_automatic_exports.sql',
            $auto,
            1060
        ));
        self::assertTrue($schema->isRecoverableDuplicate(
            '202609060010_add_project_usage_recorded_at.sql',
            $usage,
            1060
        ));

        $pdo->exec(
            "UPDATE information_schema.COLUMNS SET COLUMN_DEFAULT = '1' "
            . "WHERE TABLE_NAME = 'projects' AND COLUMN_NAME = 'auto_render_requested'"
        );
        self::assertFalse($schema->isRecoverableDuplicate(
            '202609060004_add_project_automatic_exports.sql',
            $auto,
            1060
        ));
        $pdo->exec(
            "UPDATE information_schema.STATISTICS SET NON_UNIQUE = 0 "
            . "WHERE TABLE_NAME = 'projects' AND INDEX_NAME = 'idx_projects_user_usage'"
        );
        self::assertFalse($schema->isRecoverableDuplicate(
            '202609060010_add_project_usage_recorded_at.sql',
            $usage,
            1060
        ));
    }

    /** @dataProvider cueDriftCases */
    public function testCueSchemaRejectsSemanticallyDifferentMetadata(string $change): void
    {
        $pdo = $this->metadataPdo();
        $this->seedCueMetadata($pdo, true);
        $pdo->exec($change);

        $this->expectException(RuntimeException::class);
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060003_create_clip_subtitle_cues.sql');
    }

    public static function cueDriftCases(): array
    {
        return [
            'non unicode subtitles' => ["UPDATE information_schema.COLUMNS SET CHARACTER_SET_NAME='latin1', COLLATION_NAME='latin1_swedish_ci' WHERE COLUMN_NAME='text'"],
            'generated subtitles' => ["UPDATE information_schema.COLUMNS SET EXTRA='VIRTUAL GENERATED' WHERE COLUMN_NAME='text'"],
            'cross schema foreign key' => ["UPDATE information_schema.KEY_COLUMN_USAGE SET REFERENCED_TABLE_SCHEMA='other_database'"],
            'composite foreign key' => ["INSERT INTO information_schema.KEY_COLUMN_USAGE (CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,ORDINAL_POSITION) VALUES ('unit','fk_cue_track','clip_subtitle_cues','cue_index','clip_subtitle_tracks','other_id',2)"],
            'cascading parent updates' => ["UPDATE information_schema.REFERENTIAL_CONSTRAINTS SET UPDATE_RULE='CASCADE'"],
            'non transactional table' => ["UPDATE information_schema.TABLES SET ENGINE='MyISAM'"],
        ];
    }

    /** @dataProvider unsupportedExtraCases */
    public function testUsageRecoveryRejectsUnexpectedColumnBehavior(string $extra): void
    {
        $pdo = $this->metadataPdo();
        $pdo->prepare('INSERT INTO information_schema.COLUMNS '
            . '(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA) '
            . "VALUES ('unit','projects','usage_recorded_at','datetime','datetime','YES',NULL,?)"
        )->execute([$extra]);
        $pdo->exec("INSERT INTO information_schema.STATISTICS VALUES "
            . "('unit','projects','idx_projects_user_usage','user_id',1,NULL,1),"
            . "('unit','projects','idx_projects_user_usage','usage_recorded_at',1,NULL,2)");

        self::assertFalse((new SaasMigrationSchema($pdo))->isRecoverableDuplicate(
            '202609060010_add_project_usage_recorded_at.sql',
            'ALTER TABLE projects ADD COLUMN usage_recorded_at DATETIME NULL AFTER processed_duration_seconds, '
                . 'ADD KEY idx_projects_user_usage (user_id, usage_recorded_at)',
            1060
        ));
    }

    public static function unsupportedExtraCases(): array
    {
        return [['on update CURRENT_TIMESTAMP'], ['VIRTUAL GENERATED'], ['STORED GENERATED'], ['INVISIBLE']];
    }

    public function testMariaDbSameNamedCheckOnAnotherTableCannotMaskOwnDrift(): void
    {
        $pdo = $this->metadataPdo();
        $this->seedCueMetadata($pdo, true);
        $pdo->exec("UPDATE information_schema.CHECK_CONSTRAINTS SET CHECK_CLAUSE='0' WHERE CONSTRAINT_NAME='chk_cue_interval'");
        $pdo->exec("INSERT INTO information_schema.CHECK_CONSTRAINTS VALUES ('unit','chk_cue_interval','start_ms < end_ms AND end_ms <= 180000','another_table')");

        $this->expectException(RuntimeException::class);
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060003_create_clip_subtitle_cues.sql');
    }

    public function testMariaDbUnrelatedSameNamedCheckCannotRejectValidSchema(): void
    {
        $pdo = $this->metadataPdo();
        $this->seedCueMetadata($pdo, true);
        $pdo->exec("INSERT INTO information_schema.CHECK_CONSTRAINTS VALUES ('unit','chk_cue_interval','zzz > 0','another_table')");

        (new SaasMigrationSchema($pdo))->verifyMigration('202609060003_create_clip_subtitle_cues.sql');
        self::assertTrue(true);
    }

    public function testMySqlChecksWithoutTableNameMetadataRemainSupported(): void
    {
        $pdo = $this->metadataPdo(false);
        $this->seedCueMetadata($pdo, true);
        $pdo->exec("UPDATE information_schema.COLUMNS SET DATA_TYPE='json', COLUMN_TYPE='json' WHERE COLUMN_NAME='words_json'");

        (new SaasMigrationSchema($pdo))->verifyMigration('202609060003_create_clip_subtitle_cues.sql');
        self::assertTrue(true);
    }

    public function testMariaDbChecksWithoutEnforcedMetadataRemainSupported(): void
    {
        $pdo = $this->metadataPdo();
        $this->seedCueMetadata($pdo, true);
        $pdo->exec("DELETE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='information_schema' AND COLUMN_NAME='ENFORCED'");

        (new SaasMigrationSchema($pdo))->verifyMigration('202609060003_create_clip_subtitle_cues.sql');
        self::assertTrue(true);
    }

    public function testSourceCleanupRecoveryRequiresExactStatementAndCompleteSurvivingSchema(): void
    {
        $pdo = $this->metadataPdo();
        $this->seedSourceCleanupMetadata($pdo);
        $schema = new SaasMigrationSchema($pdo);
        $migration = '202609060030_create_source_artifact_cleanups.sql';
        $statement = 'ALTER TABLE project_sources ADD INDEX idx_project_sources_object_key (object_key)';

        self::assertTrue($schema->isRecoverableDuplicate($migration, $statement, 1061));
        self::assertFalse($schema->isRecoverableDuplicate($migration, $statement, 1060));
        self::assertFalse($schema->isRecoverableDuplicate('unmapped.sql', $statement, 1061));
        self::assertFalse($schema->isRecoverableDuplicate($migration, $statement . " COMMENT 'changed'", 1061));
        $schema->verifyMigration($migration);
    }

    /** @dataProvider sourceCleanupDriftCases */
    public function testSourceCleanupPostconditionRejectsPartialOrDivergentSchema(string $change): void
    {
        $pdo = $this->metadataPdo();
        $this->seedSourceCleanupMetadata($pdo);
        $pdo->exec($change);

        $this->expectException(RuntimeException::class);
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060030_create_source_artifact_cleanups.sql');
    }

    /** @dataProvider sourceCleanupDriftCases */
    public function testSourceCleanupDuplicateDoesNotForgiveSchemaDrift(string $change): void
    {
        $pdo = $this->metadataPdo();
        $this->seedSourceCleanupMetadata($pdo);
        $pdo->exec($change);

        self::assertFalse((new SaasMigrationSchema($pdo))->isRecoverableDuplicate(
            '202609060030_create_source_artifact_cleanups.sql',
            'ALTER TABLE project_sources ADD INDEX idx_project_sources_object_key (object_key)',
            1061
        ));
    }

    public static function sourceCleanupDriftCases(): array
    {
        return [
            'table missing' => ["DELETE FROM information_schema.COLUMNS WHERE TABLE_NAME='source_artifact_cleanups'"],
            'non transactional' => ["UPDATE information_schema.TABLES SET ENGINE='MyISAM' WHERE TABLE_NAME='source_artifact_cleanups'"],
            'case insensitive key' => ["UPDATE information_schema.COLUMNS SET COLLATION_NAME='ascii_general_ci' WHERE COLUMN_NAME='object_key'"],
            'nullable deadline' => ["UPDATE information_schema.COLUMNS SET IS_NULLABLE='YES' WHERE COLUMN_NAME='cleanup_after'"],
            'primary missing' => ["DELETE FROM information_schema.STATISTICS WHERE INDEX_NAME='PRIMARY'"],
            'due index order' => ["UPDATE information_schema.STATISTICS SET SEQ_IN_INDEX=3-SEQ_IN_INDEX WHERE INDEX_NAME='idx_source_cleanups_due'"],
            'reference index prefix' => ["UPDATE information_schema.STATISTICS SET SUB_PART=40 WHERE INDEX_NAME='idx_project_sources_object_key'"],
            'reference index missing' => ["DELETE FROM information_schema.STATISTICS WHERE INDEX_NAME='idx_project_sources_object_key'"],
            'created at auto updates' => ["UPDATE information_schema.COLUMNS SET EXTRA='on update CURRENT_TIMESTAMP' WHERE COLUMN_NAME='created_at'"],
            'cascading cleanup obligation' => [
                "INSERT INTO information_schema.TABLE_CONSTRAINTS VALUES ('unit','unit','source_artifact_cleanups','fk_cleanup_job','FOREIGN KEY','YES');"
                . "INSERT INTO information_schema.KEY_COLUMN_USAGE (CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME) VALUES ('unit','fk_cleanup_job','source_artifact_cleanups','job_id','jobs','id');"
                . "INSERT INTO information_schema.REFERENTIAL_CONSTRAINTS VALUES ('unit','fk_cleanup_job','CASCADE','source_artifact_cleanups','RESTRICT')",
            ],
        ];
    }

    public function testMySqlDefaultGeneratedTimestampAndMariaDbQuotedDefaultsAreAccepted(): void
    {
        $pdo = $this->metadataPdo();
        $this->seedSourceCleanupMetadata($pdo);
        $pdo->exec("UPDATE information_schema.COLUMNS SET EXTRA='DEFAULT_GENERATED', COLUMN_DEFAULT='CURRENT_TIMESTAMP' WHERE COLUMN_NAME='created_at'");
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060030_create_source_artifact_cleanups.sql');
        self::assertTrue(true);
        $pdo->exec("UPDATE information_schema.COLUMNS SET EXTRA='', COLUMN_DEFAULT='current_timestamp()' WHERE COLUMN_NAME='created_at'");
        $pdo->exec("UPDATE information_schema.COLUMNS SET COLUMN_DEFAULT='NULL' WHERE COLUMN_NAME IN ('job_id','lease_token_hash')");
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060030_create_source_artifact_cleanups.sql');
        self::assertTrue(true);
    }

    /** @dataProvider additionalMediaColumnCases */
    public function testMediaSurvivorsRequireAdditionalColumnsToAcceptOmittedInsertValues(
        string $table,
        string $nullable,
        ?string $default,
        string $extra,
        bool $compatible
    ): void {
        $pdo = $this->metadataPdo();
        $this->seedPublicationMetadata($pdo);
        $pdo->prepare('INSERT INTO information_schema.COLUMNS '
            . '(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA) '
            . "VALUES ('unit',?,'future_field','int','int',?,?,?)"
        )->execute([$table, $nullable, $default, $extra]);

        if (!$compatible) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Migration schema postcondition failed.');
        }
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060051_create_publication_preparations.sql');
        if ($compatible) {
            self::assertTrue(true, 'Additional columns that accept omitted values preserve repository inserts.');
        }
    }

    public static function additionalMediaColumnCases(): array
    {
        $cases = [];
        foreach (['publication_preparations', 'publication_events'] as $table) {
            foreach ([
                'required without default' => ['NO', null, '', false],
                'MariaDB SQL NULL default' => ['NO', 'NULL', '', false],
                'update-only timestamp' => ['NO', null, 'on update CURRENT_TIMESTAMP', false],
                'default marker is not a generated column' => ['NO', null, 'DEFAULT_GENERATED', false],
                'invisible required column' => ['NO', null, 'INVISIBLE', false],
                'nullable column' => ['YES', null, '', true],
                'numeric zero default' => ['NO', '0', '', true],
                'empty string default' => ['NO', '', '', true],
                'MariaDB quoted NULL string default' => ['NO', "'NULL'", '', true],
                'expression default' => ['NO', 'current_timestamp()', 'DEFAULT_GENERATED', true],
                'virtual generated column' => ['NO', null, 'VIRTUAL GENERATED', true],
                'stored generated column' => ['NO', null, 'STORED GENERATED', true],
                'persistent generated column' => ['NO', null, 'PERSISTENT GENERATED', true],
                'autoincrement column' => ['NO', null, 'auto_increment', true],
            ] as $name => $case) {
                $cases[$table . ': ' . $name] = [$table, ...$case];
            }
        }

        return $cases;
    }

    /** @dataProvider additionalDefaultEngineCases */
    public function testAdditionalDefaultMetadataUsesEngineOnlyForAmbiguousNullStrings(
        string $version,
        ?string $default,
        bool $compatible,
        int $versionReads
    ): void {
        $pdo = $this->metadataPdo(true, $version);
        $this->seedPublicationMetadata($pdo);
        $pdo->prepare('INSERT INTO information_schema.COLUMNS '
            . '(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA) '
            . "VALUES ('unit','publication_events','future_text','varchar','varchar(30)','NO',?,30,'utf8mb4','utf8mb4_unicode_ci','')"
        )->execute([$default]);

        $rejected = false;
        try {
            (new SaasMigrationSchema($pdo))->verifyMigration('202609060051_create_publication_preparations.sql');
        } catch (RuntimeException) {
            $rejected = true;
        }
        self::assertSame(!$compatible, $rejected);
        self::assertSame($versionReads, $pdo->serverVersionReads);
    }

    public static function additionalDefaultEngineCases(): array
    {
        return [
            'MySQL literal NULL string is a usable default' => ['8.0.36', 'NULL', true, 1],
            'MariaDB raw SQL NULL is not a usable default' => ['5.5.5-10.4.32-MariaDB', 'NULL', false, 1],
            'MariaDB quoted literal NULL is usable without engine lookup' => ['10.4.32-MariaDB', "'NULL'", true, 0],
            'MySQL missing default is actual PHP null' => ['8.0.36', null, false, 0],
            'MariaDB missing default is actual PHP null' => ['10.4.32-MariaDB', null, false, 0],
            'MySQL zero string default needs no engine lookup' => ['8.0.36', '0', true, 0],
            'MariaDB quoted zero default needs no engine lookup' => ['10.4.32-MariaDB', "'0'", true, 0],
            'MySQL empty string default needs no engine lookup' => ['8.0.36', '', true, 0],
            'MariaDB quoted empty default needs no engine lookup' => ['10.4.32-MariaDB', "''", true, 0],
        ];
    }

    public function testAdditionalRequiredColumnsDoNotTurnExistingPartialColumnGuardsIntoFullTableContracts(): void
    {
        $pdo = $this->metadataPdo();
        $pdo->exec("INSERT INTO information_schema.COLUMNS "
            . "(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA) VALUES "
            . "('unit','projects','auto_render_requested','tinyint','tinyint unsigned','NO','0',''),"
            . "('unit','projects','existing_required_field','varchar','varchar(30)','NO',NULL,'')");
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060004_add_project_automatic_exports.sql');
        self::assertTrue(true);

        $this->seedSourceCleanupMetadata($pdo);
        $pdo->exec("INSERT INTO information_schema.COLUMNS "
            . "(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA) VALUES "
            . "('unit','source_artifact_cleanups','future_required_field','int','int','NO',NULL,'')");
        (new SaasMigrationSchema($pdo))->verifyMigration('202609060030_create_source_artifact_cleanups.sql');
        self::assertTrue(true, 'The new rule is limited to the seven media contracts.');
    }

    private function seedPublicationMetadata(PDO $pdo): void
    {
        $insert = $pdo->prepare('INSERT INTO information_schema.COLUMNS '
            . '(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA) '
            . "VALUES ('unit',?,?,?,?,?,?,?,?,?,?)");
        $common = [
            ['id', 'bigint', 'bigint unsigned', 'NO', null, null, null, null, 'auto_increment'],
            ['user_id', 'bigint', 'bigint unsigned', 'NO', null, null, null, null, ''],
            ['created_at', 'timestamp', 'timestamp', 'NO', 'current_timestamp()', null, null, null, ''],
        ];
        $tables = [
            'publication_preparations' => [
                ['clip_id', 'bigint', 'bigint unsigned', 'NO', null, null, null, null, ''],
                ['render_revision', 'int', 'int unsigned', 'NO', null, null, null, null, ''],
                ['thumbnail_id', 'bigint', 'bigint unsigned', 'YES', null, null, null, null, ''],
                ['platform', 'varchar', 'varchar(30)', 'NO', null, 30, 'utf8mb4', 'utf8mb4_unicode_ci', ''],
                ['metadata_json', 'json', 'json', 'NO', null, null, null, null, ''],
                ['status', 'varchar', 'varchar(30)', 'NO', 'draft', 30, 'utf8mb4', 'utf8mb4_unicode_ci', ''],
                ['version', 'int', 'int unsigned', 'NO', '1', null, null, null, ''],
                ['updated_at', 'timestamp', 'timestamp', 'NO', 'current_timestamp()', null, null, null, 'on update CURRENT_TIMESTAMP'],
            ],
            'publication_events' => [
                ['publication_id', 'bigint', 'bigint unsigned', 'NO', null, null, null, null, ''],
                ['version', 'int', 'int unsigned', 'NO', null, null, null, null, ''],
                ['status', 'varchar', 'varchar(30)', 'NO', null, 30, 'utf8mb4', 'utf8mb4_unicode_ci', ''],
                ['snapshot_json', 'json', 'json', 'NO', null, null, null, null, ''],
            ],
        ];
        foreach ($tables as $table => $columns) {
            $pdo->prepare("INSERT INTO information_schema.TABLES VALUES ('unit',?,'InnoDB')")->execute([$table]);
            foreach ([...$common, ...$columns] as $row) {
                $insert->execute([$table, ...$row]);
            }
        }
        $index = $pdo->prepare("INSERT INTO information_schema.STATISTICS VALUES ('unit',?,?,?, ?,NULL,?)");
        foreach ([
            ['publication_preparations', 'PRIMARY', 'id', 0, 1],
            ['publication_preparations', 'idx_publication_owner_clip', 'user_id', 1, 1],
            ['publication_preparations', 'idx_publication_owner_clip', 'clip_id', 1, 2],
            ['publication_events', 'PRIMARY', 'id', 0, 1],
            ['publication_events', 'uq_publication_event_version', 'publication_id', 0, 1],
            ['publication_events', 'uq_publication_event_version', 'version', 0, 2],
        ] as $row) {
            $index->execute($row);
        }
        foreach ([
            ['publication_preparations', 'publication_preparations_ibfk_1', 'clip_id', 'clips'],
            ['publication_preparations', 'publication_preparations_ibfk_2', 'user_id', 'users'],
            ['publication_preparations', 'publication_preparations_ibfk_3', 'thumbnail_id', 'clip_thumbnails'],
            ['publication_events', 'publication_events_ibfk_1', 'publication_id', 'publication_preparations'],
            ['publication_events', 'publication_events_ibfk_2', 'user_id', 'users'],
        ] as [$table, $name, $column, $parent]) {
            $pdo->prepare("INSERT INTO information_schema.TABLE_CONSTRAINTS VALUES ('unit','unit',?,?,'FOREIGN KEY','YES')")
                ->execute([$table, $name]);
            $pdo->prepare('INSERT INTO information_schema.KEY_COLUMN_USAGE '
                . '(CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME) '
                . "VALUES ('unit',?,?,?,?,'id')")->execute([$name, $table, $column, $parent]);
            $pdo->prepare("INSERT INTO information_schema.REFERENTIAL_CONSTRAINTS VALUES ('unit',?,'RESTRICT',?,'RESTRICT')")
                ->execute([$name, $table]);
        }
    }

    private function seedSourceCleanupMetadata(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO information_schema.TABLES VALUES ('unit','source_artifact_cleanups','InnoDB')");
        $insert = $pdo->prepare('INSERT INTO information_schema.COLUMNS '
            . '(TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_MAXIMUM_LENGTH,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA) '
            . "VALUES ('unit','source_artifact_cleanups',?,?,?,?,?,?,?,?,?)");
        foreach ([
            ['object_key','varchar','varchar(255)','NO',null,255,'ascii','ascii_bin',''],
            ['job_id','bigint','bigint unsigned','YES',null,null,null,null,''],
            ['lease_token_hash','char','char(64)','YES',null,64,'ascii','ascii_bin',''],
            ['cleanup_after','datetime','datetime','NO',null,null,null,null,''],
            ['created_at','timestamp','timestamp','NO','current_timestamp()',null,null,null,''],
        ] as $row) {
            $insert->execute($row);
        }
        $pdo->exec("INSERT INTO information_schema.STATISTICS VALUES "
            . "('unit','source_artifact_cleanups','PRIMARY','object_key',0,NULL,1),"
            . "('unit','source_artifact_cleanups','idx_source_cleanups_due','cleanup_after',1,NULL,1),"
            . "('unit','source_artifact_cleanups','idx_source_cleanups_due','object_key',1,NULL,2),"
            . "('unit','project_sources','idx_project_sources_object_key','object_key',1,NULL,1)");
    }

    private function metadataPdo(bool $checkTableName = true, string $serverVersion = '10.4.32-MariaDB'): PDO
    {
        $pdo = new class($serverVersion) extends PDO {
            public int $serverVersionReads = 0;

            public function __construct(private string $serverVersion)
            {
                parent::__construct('sqlite::memory:');
            }

            public function getAttribute(int $attribute): mixed
            {
                if ($attribute === PDO::ATTR_SERVER_VERSION) {
                    ++$this->serverVersionReads;
                    return $this->serverVersion;
                }

                return parent::getAttribute($attribute);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('DATABASE', static fn (): string => 'unit');
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT,TABLE_NAME TEXT,ENGINE TEXT)');
        $pdo->exec(
            'CREATE TABLE information_schema.COLUMNS ('
            . 'TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, DATA_TYPE TEXT, COLUMN_TYPE TEXT, '
            . 'IS_NULLABLE TEXT, COLUMN_DEFAULT TEXT NULL, CHARACTER_MAXIMUM_LENGTH INTEGER NULL, '
            . 'CHARACTER_SET_NAME TEXT NULL, COLLATION_NAME TEXT NULL, EXTRA TEXT)'
        );
        $pdo->exec(
            'CREATE TABLE information_schema.STATISTICS ('
            . 'TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT, COLUMN_NAME TEXT, '
            . 'NON_UNIQUE INTEGER, SUB_PART INTEGER NULL, SEQ_IN_INDEX INTEGER)'
        );
        $pdo->exec(
            'CREATE TABLE information_schema.TABLE_CONSTRAINTS ('
            . 'TABLE_SCHEMA TEXT, CONSTRAINT_SCHEMA TEXT, TABLE_NAME TEXT, '
            . 'CONSTRAINT_NAME TEXT, CONSTRAINT_TYPE TEXT, ENFORCED TEXT NULL)'
        );
        $pdo->exec(
            'CREATE TABLE information_schema.KEY_COLUMN_USAGE ('
            . 'CONSTRAINT_SCHEMA TEXT, CONSTRAINT_NAME TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, '
            . 'REFERENCED_TABLE_NAME TEXT NULL, REFERENCED_COLUMN_NAME TEXT NULL, '
            . "REFERENCED_TABLE_SCHEMA TEXT DEFAULT 'unit', ORDINAL_POSITION INTEGER DEFAULT 1)"
        );
        $pdo->exec(
            'CREATE TABLE information_schema.REFERENTIAL_CONSTRAINTS ('
            . 'CONSTRAINT_SCHEMA TEXT, CONSTRAINT_NAME TEXT, DELETE_RULE TEXT, '
            . "TABLE_NAME TEXT DEFAULT 'clip_subtitle_cues', UPDATE_RULE TEXT DEFAULT 'RESTRICT')"
        );
        $pdo->exec(
            'CREATE TABLE information_schema.CHECK_CONSTRAINTS ('
            . 'CONSTRAINT_SCHEMA TEXT, CONSTRAINT_NAME TEXT, CHECK_CLAUSE TEXT'
            . ($checkTableName ? ", TABLE_NAME TEXT DEFAULT 'clip_subtitle_cues'" : '') . ')'
        );
        if ($checkTableName) {
            $pdo->exec("INSERT INTO information_schema.COLUMNS (TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME) VALUES ('information_schema','CHECK_CONSTRAINTS','TABLE_NAME')");
        }
        $pdo->exec(
            "INSERT INTO information_schema.COLUMNS "
            . "(TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, EXTRA) "
            . "VALUES ('information_schema', 'TABLE_CONSTRAINTS', 'ENFORCED', 'varchar', 'varchar(3)', 'YES', '')"
        );

        return $pdo;
    }

    private function seedCueMetadata(PDO $pdo, bool $jsonCheck): void
    {
        $pdo->exec("INSERT INTO information_schema.TABLES VALUES ('unit','clip_subtitle_cues','InnoDB')");
        $columns = [
            ['id', 'bigint', 'bigint unsigned', 'NO', null, null, 'auto_increment'],
            ['track_id', 'bigint', 'bigint unsigned', 'NO', null, null, ''],
            ['cue_index', 'smallint', 'smallint unsigned', 'NO', null, null, ''],
            ['start_ms', 'int', 'int unsigned', 'NO', null, null, ''],
            ['end_ms', 'int', 'int unsigned', 'NO', null, null, ''],
            ['text', 'varchar', 'varchar(350)', 'NO', null, 350, ''],
            ['words_json', 'longtext', 'longtext', 'YES', null, 4294967295, ''],
        ];
        $insertColumn = $pdo->prepare(
            'INSERT INTO information_schema.COLUMNS '
            . '(TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, '
            . 'COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, EXTRA) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($columns as [$name, $type, $columnType, $nullable, $default, $length, $extra]) {
            $insertColumn->execute([
                'unit', 'clip_subtitle_cues', $name, $type, $columnType,
                $nullable, $default, $length, $extra,
            ]);
        }
        $pdo->exec("UPDATE information_schema.COLUMNS SET CHARACTER_SET_NAME='utf8mb4', COLLATION_NAME='utf8mb4_unicode_ci' WHERE TABLE_NAME='clip_subtitle_cues' AND COLUMN_NAME='text'");

        $insertIndex = $pdo->prepare(
            'INSERT INTO information_schema.STATISTICS '
            . '(TABLE_SCHEMA, TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART, SEQ_IN_INDEX) '
            . 'VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        $insertIndex->execute(['unit', 'clip_subtitle_cues', 'PRIMARY', 'id', 0, 1]);
        $insertIndex->execute(['unit', 'clip_subtitle_cues', 'uq_subtitle_cue', 'track_id', 0, 1]);
        $insertIndex->execute(['unit', 'clip_subtitle_cues', 'uq_subtitle_cue', 'cue_index', 0, 2]);

        $pdo->exec(
            "INSERT INTO information_schema.TABLE_CONSTRAINTS "
            . "(TABLE_SCHEMA, CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED) "
            . "VALUES ('unit', 'unit', 'clip_subtitle_cues', 'fk_cue_track', 'FOREIGN KEY', 'YES')"
        );
        $pdo->exec(
            "INSERT INTO information_schema.KEY_COLUMN_USAGE "
            . "(CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME) "
            . "VALUES ('unit', 'fk_cue_track', 'clip_subtitle_cues', 'track_id', 'clip_subtitle_tracks', 'id')"
        );
        $pdo->exec(
            "INSERT INTO information_schema.REFERENTIAL_CONSTRAINTS (CONSTRAINT_SCHEMA,CONSTRAINT_NAME,DELETE_RULE) "
            . "VALUES ('unit', 'fk_cue_track', 'CASCADE')"
        );

        $this->insertCheck($pdo, 'chk_cue_interval', 'start_ms < end_ms AND end_ms <= 180000');
        if ($jsonCheck) {
            $this->insertCheck($pdo, 'words_json', 'json_valid(`words_json`)');
        }
    }

    private function insertCheck(PDO $pdo, string $name, string $clause): void
    {
        $pdo->prepare(
            'INSERT INTO information_schema.TABLE_CONSTRAINTS '
            . '(TABLE_SCHEMA, CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED) '
            . "VALUES ('unit', 'unit', 'clip_subtitle_cues', ?, 'CHECK', 'YES')"
        )->execute([$name]);
        $pdo->prepare(
            'INSERT INTO information_schema.CHECK_CONSTRAINTS '
            . "(CONSTRAINT_SCHEMA, CONSTRAINT_NAME, CHECK_CLAUSE) VALUES ('unit', ?, ?)"
        )->execute([$name, $clause]);
    }
}
