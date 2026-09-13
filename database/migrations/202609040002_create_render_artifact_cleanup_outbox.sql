CREATE TABLE IF NOT EXISTS render_artifact_cleanups (
    object_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    cleanup_after DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (object_key),
    INDEX idx_render_artifact_cleanups_created (created_at)
) ENGINE=InnoDB;
