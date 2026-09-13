CREATE TABLE IF NOT EXISTS clip_subtitle_tracks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    editor_profile_id BIGINT UNSIGNED NOT NULL,
    language VARCHAR(35) NOT NULL DEFAULT 'und',
    status ENUM('pending','ready','failed') NOT NULL DEFAULT 'pending',
    error_code VARCHAR(64) NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subtitle_editor (editor_profile_id),
    CONSTRAINT fk_subtitle_editor FOREIGN KEY (editor_profile_id) REFERENCES clip_editor_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
