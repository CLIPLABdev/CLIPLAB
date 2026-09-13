CREATE TABLE IF NOT EXISTS clip_editor_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clip_id BIGINT UNSIGNED NOT NULL,
    render_revision INT UNSIGNED NOT NULL,
    parent_clip_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    options_json JSON NOT NULL,
    transcript_mode ENUM('none','manual','auto') NOT NULL,
    duration_ms INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_editor_clip_revision (clip_id, render_revision),
    UNIQUE KEY uq_editor_user_request (user_id, request_key),
    CONSTRAINT fk_editor_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE,
    CONSTRAINT fk_editor_parent FOREIGN KEY (parent_clip_id) REFERENCES clips(id) ON DELETE SET NULL,
    CONSTRAINT fk_editor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_editor_duration CHECK (duration_ms BETWEEN 1000 AND 180000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NULL identifies an edited derivative, not an additional AI suggestion.
-- MySQL permits multiple NULLs in the existing unique suggestion index.
ALTER TABLE clips MODIFY COLUMN suggestion_index TINYINT UNSIGNED NULL;
