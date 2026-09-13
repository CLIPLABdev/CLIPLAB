CREATE TABLE IF NOT EXISTS user_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    policy_version VARCHAR(32) NOT NULL,
    granted_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_user_consents_version (user_id, purpose, policy_version),
    KEY idx_user_consents_active (user_id, purpose, policy_version, revoked_at),
    CONSTRAINT fk_user_consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_user_consents_dates CHECK (revoked_at IS NULL OR revoked_at >= granted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
