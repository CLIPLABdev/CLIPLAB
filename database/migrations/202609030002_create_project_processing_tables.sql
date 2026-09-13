ALTER TABLE projects ADD COLUMN ingest_key CHAR(64) NULL AFTER user_id;
ALTER TABLE projects ADD COLUMN progress TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (progress <= 100) AFTER status;
ALTER TABLE projects ADD COLUMN error_code VARCHAR(64) NULL AFTER progress;
ALTER TABLE projects ADD COLUMN error_message VARCHAR(255) NULL AFTER error_code;
ALTER TABLE projects ADD UNIQUE INDEX uq_projects_user_ingest (user_id, ingest_key);

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
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (progress <= 100),
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
