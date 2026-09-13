CREATE TABLE gemini_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    api_key_ciphertext TEXT NULL,
    model VARCHAR(128) NULL,
    last_test_status ENUM('untested', 'success', 'failed') NOT NULL DEFAULT 'untested',
    last_test_code VARCHAR(64) NULL,
    last_tested_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_gemini_settings_updated_by (updated_by),
    CONSTRAINT fk_gemini_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
