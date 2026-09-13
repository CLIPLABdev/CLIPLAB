ALTER TABLE projects
    ADD COLUMN usage_recorded_at DATETIME NULL AFTER processed_duration_seconds,
    ADD KEY idx_projects_user_usage (user_id, usage_recorded_at);

UPDATE projects
SET usage_recorded_at = created_at
WHERE processed_duration_seconds > 0
  AND usage_recorded_at IS NULL;
