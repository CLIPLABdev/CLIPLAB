CREATE TABLE IF NOT EXISTS publication_preparations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, clip_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 render_revision INT UNSIGNED NOT NULL, thumbnail_id BIGINT UNSIGNED NULL, platform VARCHAR(30) NOT NULL,
 metadata_json JSON NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'draft', version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_publication_owner_clip(user_id,clip_id), FOREIGN KEY(clip_id) REFERENCES clips(id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(thumbnail_id) REFERENCES clip_thumbnails(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS publication_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, publication_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL, status VARCHAR(30) NOT NULL, snapshot_json JSON NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_publication_event_version(publication_id,version),
 FOREIGN KEY(publication_id) REFERENCES publication_preparations(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
