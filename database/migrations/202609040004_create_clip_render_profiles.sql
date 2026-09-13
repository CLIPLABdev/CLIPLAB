CREATE TABLE IF NOT EXISTS clip_render_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clip_id BIGINT UNSIGNED NOT NULL,
    render_revision INT UNSIGNED NOT NULL,
    aspect_ratio ENUM('original', '9:16', '1:1', '16:9', '4:5') NOT NULL,
    reframe_mode ENUM('original', 'center', 'manual', 'auto') NOT NULL,
    output_width SMALLINT UNSIGNED NULL,
    output_height SMALLINT UNSIGNED NULL,
    detector_version VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clip_render_profiles_revision (clip_id, render_revision),
    KEY idx_clip_render_profiles_clip_created (clip_id, created_at),
    CONSTRAINT fk_clip_render_profiles_clip FOREIGN KEY (clip_id) REFERENCES clips (id) ON DELETE CASCADE,
    CONSTRAINT chk_clip_render_profiles_revision CHECK (render_revision > 0),
    CONSTRAINT chk_clip_render_profiles_shape CHECK (
        (aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL)
        OR (aspect_ratio = '9:16' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 1280)
        OR (aspect_ratio = '1:1' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 720)
        OR (aspect_ratio = '16:9' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 1280 AND output_height = 720)
        OR (aspect_ratio = '4:5' AND reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND output_width = 720 AND output_height = 900)
    ),
    CONSTRAINT chk_clip_render_profiles_detector CHECK (
        (reframe_mode = 'auto' AND detector_version IS NOT NULL AND detector_version = 'tasks-vision-1.0.1/blazeface-short-f16-r1')
        OR (reframe_mode <> 'auto' AND detector_version IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
