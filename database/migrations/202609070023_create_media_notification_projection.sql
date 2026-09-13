CREATE TABLE IF NOT EXISTS communication_media_checkpoints (
    source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    high_water_id BIGINT UNSIGNED NOT NULL,
    cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    initialized_at DATETIME NOT NULL,
    last_run_at DATETIME NULL,
    last_error_code VARCHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_media_observations (
    source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    last_state VARCHAR(32) NOT NULL,
    last_revision VARCHAR(32) NOT NULL,
    observed_at DATETIME NOT NULL,
    PRIMARY KEY (source, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_media_receipts (
    dedupe_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    source VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(100) NOT NULL,
    disposition ENUM('emitted','baseline','suppressed') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_media_receipt_entity (source,entity_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
