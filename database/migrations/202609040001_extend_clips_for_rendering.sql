ALTER TABLE clips
    ADD COLUMN render_start_time DECIMAL(10,3) UNSIGNED NULL AFTER end_time,
    ADD COLUMN render_end_time DECIMAL(10,3) UNSIGNED NULL AFTER render_start_time,
    ADD COLUMN render_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER render_end_time,
    ADD COLUMN render_error_code VARCHAR(64) NULL AFTER render_revision,
    ADD COLUMN output_size_bytes BIGINT UNSIGNED NULL AFTER output_file,
    ADD COLUMN thumbnail_size_bytes BIGINT UNSIGNED NULL AFTER thumbnail,
    ADD COLUMN approved_at DATETIME NULL AFTER thumbnail_size_bytes,
    ADD COLUMN render_requested_at DATETIME NULL AFTER approved_at,
    ADD COLUMN rendered_at DATETIME NULL AFTER render_requested_at;
