<?php

declare(strict_types=1);

namespace App\Core;

/** Contracts for the additive media tables, checked by SaasMigrationSchema. */
final class MediaMigrationSchema
{
    /** @return list<array<string,mixed>> */
    public static function tablesForMigration(string $migration): array
    {
        $tables = self::contracts($migration);
        foreach ($tables as &$table) {
            foreach ($table['columns'] as &$column) {
                if (in_array($column['type'], ['varchar', 'char'], true)) {
                    $column += ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
                }
            }
            unset($column);
        }
        unset($table);

        return $tables;
    }

    /** @return list<array<string,mixed>> */
    private static function contracts(string $migration): array
    {
        $id = ['type' => 'bigint', 'unsigned' => true, 'nullable' => false, 'auto_increment' => true];
        $owner = ['type' => 'bigint', 'unsigned' => true, 'nullable' => false];
        $optionalId = ['type' => 'bigint', 'unsigned' => true, 'nullable' => true, 'default' => null];
        $revision = ['type' => 'int', 'unsigned' => true, 'nullable' => false];
        $json = ['type' => 'json', 'nullable' => false];
        $created = ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'];
        $timestamps = ['created_at' => $created, 'updated_at' => $created + ['on_update' => 'current_timestamp']];
        $request = ['type' => 'char', 'length' => 64, 'charset' => 'ascii', 'collation' => 'ascii_bin', 'nullable' => false];
        $status = ['type' => 'varchar', 'length' => 20, 'nullable' => false, 'default' => 'pending'];
        $error = ['type' => 'varchar', 'length' => 64, 'nullable' => true, 'default' => null];

        if ($migration === '202609060045_create_editor_library.sql') {
            $aspect = ['type' => 'varchar', 'length' => 5, 'nullable' => false, 'default' => '9:16'];
            return [
                [
                    'table' => 'user_editor_templates',
                    'columns' => [
                        'id' => $id, 'user_id' => $owner,
                        'name' => ['type' => 'varchar', 'length' => 80, 'nullable' => false],
                        'category' => ['type' => 'varchar', 'length' => 16, 'nullable' => false],
                        'options_json' => $json, 'aspect_ratio' => $aspect,
                    ] + $timestamps,
                    'indexes' => ['PRIMARY' => [['id'], true], 'idx_editor_templates_owner' => [['user_id', 'updated_at'], false]],
                    'foreign_keys' => ['fk_editor_templates_owner' => ['user_id', 'users', 'id', 'CASCADE']],
                    'checks' => [],
                ],
                [
                    'table' => 'user_brand_kits',
                    'columns' => ['user_id' => $owner, 'options_json' => $json, 'aspect_ratio' => $aspect, 'favorites_json' => $json] + $timestamps,
                    'indexes' => ['PRIMARY' => [['user_id'], true]],
                    'foreign_keys' => ['fk_brand_kits_owner' => ['user_id', 'users', 'id', 'CASCADE']],
                    'checks' => [],
                ],
                [
                    'table' => 'user_brand_logos',
                    'columns' => [
                        'id' => $id, 'user_id' => $owner,
                        'object_key' => ['type' => 'varchar', 'length' => 255, 'nullable' => false],
                        'size_bytes' => $owner,
                        'sha256' => ['type' => 'char', 'length' => 64, 'nullable' => false],
                        'width' => ['type' => 'smallint', 'unsigned' => true, 'nullable' => false],
                        'height' => ['type' => 'smallint', 'unsigned' => true, 'nullable' => false],
                        'created_at' => $created,
                    ],
                    'indexes' => ['PRIMARY' => [['id'], true], 'uq_brand_logo_key' => [['object_key'], true], 'idx_brand_logos_owner' => [['user_id'], false]],
                    'foreign_keys' => ['fk_brand_logos_owner' => ['user_id', 'users', 'id', 'RESTRICT']],
                    'checks' => [],
                ],
            ];
        }
        if ($migration === '202609060050_create_thumbnail_studio.sql') {
            return [
                [
                    'table' => 'clip_thumbnail_sets',
                    'columns' => [
                        'id' => $id, 'clip_id' => $owner, 'user_id' => $owner, 'render_revision' => $revision,
                        'request_key' => $request, 'status' => $status, 'error_code' => $error,
                        'candidate_count' => $revision + ['default' => 0],
                    ] + $timestamps,
                    'indexes' => ['PRIMARY' => [['id'], true], 'uq_thumbnail_set_revision' => [['clip_id', 'render_revision'], true], 'uq_thumbnail_set_request' => [['user_id', 'request_key'], true]],
                    'foreign_keys' => [
                        'clip_thumbnail_sets_ibfk_1' => ['clip_id', 'clips', 'id', 'RESTRICT'],
                        'clip_thumbnail_sets_ibfk_2' => ['user_id', 'users', 'id', 'RESTRICT'],
                    ],
                    'checks' => [],
                ],
                [
                    'table' => 'clip_thumbnails',
                    'columns' => [
                        'id' => $id, 'set_id' => $optionalId, 'clip_id' => $owner, 'user_id' => $owner, 'render_revision' => $revision,
                        'kind' => ['type' => 'varchar', 'length' => 20, 'nullable' => false],
                        'candidate_index' => ['type' => 'int', 'unsigned' => true, 'nullable' => true, 'default' => null],
                        'offset_seconds' => ['type' => 'decimal', 'column_type' => 'decimal(9,3)', 'unsigned' => false, 'nullable' => false],
                        'base_thumbnail_id' => $optionalId, 'request_key' => array_replace($request, ['nullable' => true, 'default' => null]),
                        'options_json' => $json, 'status' => $status, 'error_code' => $error,
                        'object_key' => ['type' => 'varchar', 'length' => 255, 'charset' => 'ascii', 'collation' => 'ascii_bin', 'nullable' => true, 'default' => null],
                        'size_bytes' => $optionalId,
                        'mime_type' => ['type' => 'varchar', 'length' => 64, 'nullable' => true, 'default' => null],
                        'width' => ['type' => 'int', 'unsigned' => true, 'nullable' => true, 'default' => null],
                        'height' => ['type' => 'int', 'unsigned' => true, 'nullable' => true, 'default' => null],
                    ] + $timestamps,
                    'indexes' => [
                        'PRIMARY' => [['id'], true], 'uq_thumbnail_object' => [['object_key'], true],
                        'uq_thumbnail_candidate' => [['set_id', 'candidate_index'], true], 'uq_thumbnail_request' => [['user_id', 'request_key'], true],
                        'idx_thumbnail_owner_clip' => [['user_id', 'clip_id', 'render_revision'], false],
                    ],
                    'foreign_keys' => [
                        'clip_thumbnails_ibfk_1' => ['set_id', 'clip_thumbnail_sets', 'id', 'RESTRICT'],
                        'clip_thumbnails_ibfk_2' => ['clip_id', 'clips', 'id', 'RESTRICT'],
                        'clip_thumbnails_ibfk_3' => ['user_id', 'users', 'id', 'RESTRICT'],
                        'clip_thumbnails_ibfk_4' => ['base_thumbnail_id', 'clip_thumbnails', 'id', 'RESTRICT'],
                    ],
                    'checks' => [],
                ],
            ];
        }
        if ($migration === '202609060051_create_publication_preparations.sql') {
            return [
                [
                    'table' => 'publication_preparations',
                    'columns' => [
                        'id' => $id, 'clip_id' => $owner, 'user_id' => $owner, 'render_revision' => $revision,
                        'thumbnail_id' => $optionalId, 'platform' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
                        'metadata_json' => $json, 'status' => ['type' => 'varchar', 'length' => 30, 'nullable' => false, 'default' => 'draft'],
                        'version' => $revision + ['default' => 1],
                    ] + $timestamps,
                    'indexes' => ['PRIMARY' => [['id'], true], 'idx_publication_owner_clip' => [['user_id', 'clip_id'], false]],
                    'foreign_keys' => [
                        'publication_preparations_ibfk_1' => ['clip_id', 'clips', 'id', 'RESTRICT'],
                        'publication_preparations_ibfk_2' => ['user_id', 'users', 'id', 'RESTRICT'],
                        'publication_preparations_ibfk_3' => ['thumbnail_id', 'clip_thumbnails', 'id', 'RESTRICT'],
                    ],
                    'checks' => [],
                ],
                [
                    'table' => 'publication_events',
                    'columns' => [
                        'id' => $id, 'publication_id' => $owner, 'user_id' => $owner, 'version' => $revision,
                        'status' => ['type' => 'varchar', 'length' => 30, 'nullable' => false],
                        'snapshot_json' => $json, 'created_at' => $created,
                    ],
                    'indexes' => ['PRIMARY' => [['id'], true], 'uq_publication_event_version' => [['publication_id', 'version'], true]],
                    'foreign_keys' => [
                        'publication_events_ibfk_1' => ['publication_id', 'publication_preparations', 'id', 'RESTRICT'],
                        'publication_events_ibfk_2' => ['user_id', 'users', 'id', 'RESTRICT'],
                    ],
                    'checks' => [],
                ],
            ];
        }

        return [];
    }
}
