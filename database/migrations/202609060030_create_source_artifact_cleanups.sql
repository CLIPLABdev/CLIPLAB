CREATE TABLE IF NOT EXISTS source_artifact_cleanups (
    object_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    cleanup_after DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (object_key),
    INDEX idx_source_cleanups_due (cleanup_after, object_key)
) ENGINE=InnoDB;

ALTER TABLE project_sources ADD INDEX idx_project_sources_object_key (object_key);
