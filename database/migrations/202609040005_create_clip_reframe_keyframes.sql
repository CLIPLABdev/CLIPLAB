CREATE TABLE IF NOT EXISTS clip_reframe_keyframes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    render_profile_id BIGINT UNSIGNED NOT NULL,
    sequence_index TINYINT UNSIGNED NOT NULL,
    at_ms INT UNSIGNED NOT NULL,
    center_x DECIMAL(7,6) UNSIGNED NOT NULL,
    center_y DECIMAL(7,6) UNSIGNED NOT NULL,
    source ENUM('manual', 'detected') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clip_reframe_keyframes_sequence (render_profile_id, sequence_index),
    UNIQUE KEY uq_clip_reframe_keyframes_time (render_profile_id, at_ms),
    CONSTRAINT fk_clip_reframe_keyframes_profile FOREIGN KEY (render_profile_id) REFERENCES clip_render_profiles (id) ON DELETE CASCADE,
    CONSTRAINT chk_clip_reframe_keyframes_sequence CHECK (sequence_index <= 31),
    CONSTRAINT chk_clip_reframe_keyframes_time CHECK (at_ms <= 180000),
    CONSTRAINT chk_clip_reframe_keyframes_center_x CHECK (center_x <= 1),
    CONSTRAINT chk_clip_reframe_keyframes_center_y CHECK (center_y <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
