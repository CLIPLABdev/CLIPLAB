CREATE TABLE IF NOT EXISTS clip_thumbnail_sets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clip_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 render_revision INT UNSIGNED NOT NULL, request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending', error_code VARCHAR(64) NULL, candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_thumbnail_set_revision(clip_id,render_revision), UNIQUE KEY uq_thumbnail_set_request(user_id,request_key),
 FOREIGN KEY(clip_id) REFERENCES clips(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS clip_thumbnails (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, set_id BIGINT UNSIGNED NULL,
 clip_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, render_revision INT UNSIGNED NOT NULL,
 kind VARCHAR(20) NOT NULL, candidate_index INT UNSIGNED NULL, offset_seconds DECIMAL(9,3) NOT NULL,
 base_thumbnail_id BIGINT UNSIGNED NULL, request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 options_json JSON NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', error_code VARCHAR(64) NULL,
 object_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL, size_bytes BIGINT UNSIGNED NULL,
 mime_type VARCHAR(64) NULL, width INT UNSIGNED NULL, height INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_thumbnail_object(object_key), UNIQUE KEY uq_thumbnail_candidate(set_id,candidate_index), UNIQUE KEY uq_thumbnail_request(user_id,request_key),
 KEY idx_thumbnail_owner_clip(user_id,clip_id,render_revision),
 FOREIGN KEY(set_id) REFERENCES clip_thumbnail_sets(id), FOREIGN KEY(clip_id) REFERENCES clips(id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(base_thumbnail_id) REFERENCES clip_thumbnails(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
