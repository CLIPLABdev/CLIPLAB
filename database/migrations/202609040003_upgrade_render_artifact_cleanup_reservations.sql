ALTER TABLE render_artifact_cleanups
    ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER object_key;
ALTER TABLE render_artifact_cleanups
    ADD COLUMN lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER job_id;
ALTER TABLE render_artifact_cleanups
    ADD COLUMN cleanup_after DATETIME NULL AFTER lease_token_hash;
