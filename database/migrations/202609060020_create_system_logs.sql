CREATE TABLE system_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level ENUM('info', 'warning', 'error') NOT NULL,
    event_code VARCHAR(100) NOT NULL,
    public_message VARCHAR(255) NOT NULL,
    context_json JSON NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    target_type ENUM('user', 'plan', 'gemini_settings', 'job', 'project') NULL,
    target_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_system_logs_created (created_at, id),
    KEY idx_system_logs_level_created (level, created_at),
    KEY idx_system_logs_event_created (event_code, created_at),
    KEY idx_system_logs_actor_created (actor_id, created_at),
    CONSTRAINT fk_system_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
