CREATE TABLE IF NOT EXISTS clip_subtitle_cues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    track_id BIGINT UNSIGNED NOT NULL,
    cue_index SMALLINT UNSIGNED NOT NULL,
    start_ms INT UNSIGNED NOT NULL,
    end_ms INT UNSIGNED NOT NULL,
    text VARCHAR(350) NOT NULL,
    words_json JSON NULL,
    UNIQUE KEY uq_subtitle_cue (track_id, cue_index),
    CONSTRAINT fk_cue_track FOREIGN KEY (track_id) REFERENCES clip_subtitle_tracks(id) ON DELETE CASCADE,
    CONSTRAINT chk_cue_interval CHECK (start_ms < end_ms AND end_ms <= 180000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
