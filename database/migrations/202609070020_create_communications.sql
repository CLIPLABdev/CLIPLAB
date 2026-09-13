CREATE TABLE IF NOT EXISTS communication_email_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(100) NOT NULL,
    locale VARCHAR(16) NOT NULL DEFAULT 'pt-BR',
    subject_template VARCHAR(255) NOT NULL,
    html_template MEDIUMTEXT NOT NULL,
    text_template MEDIUMTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_communication_template_version (event, locale, version),
    KEY idx_communication_template_active (event, locale, is_active, version),
    CONSTRAINT fk_communication_template_admin FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_email_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    event VARCHAR(100) NOT NULL,
    category VARCHAR(32) NOT NULL,
    payload_ciphertext MEDIUMTEXT NULL,
    dedupe_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('pending','leased','retry','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    leased_until DATETIME NULL,
    lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider_message_id VARCHAR(255) NULL,
    last_error_code VARCHAR(64) NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_communication_outbox_dedupe (dedupe_key),
    KEY idx_communication_outbox_claim (status, available_at, leased_until, id),
    KEY idx_communication_outbox_user_status (user_id, status, created_at),
    CONSTRAINT fk_communication_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_email_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    outbox_id BIGINT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL,
    outcome ENUM('accepted','retry','failed') NOT NULL,
    provider_message_id VARCHAR(255) NULL,
    error_code VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_communication_attempt (outbox_id, attempt_number),
    CONSTRAINT fk_communication_attempt_outbox FOREIGN KEY (outbox_id) REFERENCES communication_email_outbox(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(100) NOT NULL,
    category VARCHAR(32) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body VARCHAR(1000) NOT NULL,
    dedupe_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_communication_notification_dedupe (user_id, dedupe_key),
    KEY idx_communication_notification_inbox (user_id, read_at, created_at, id),
    CONSTRAINT fk_communication_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(32) NOT NULL,
    email_enabled TINYINT(1) NOT NULL DEFAULT 1,
    in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
    marketing_opted_in_at DATETIME NULL,
    unsubscribe_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, category),
    UNIQUE KEY uq_communication_unsubscribe_token (unsubscribe_token_hash),
    CONSTRAINT fk_communication_preference_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft','scheduled','processing','completed','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_communication_campaign_schedule (status, scheduled_at, id),
    CONSTRAINT fk_communication_campaign_template FOREIGN KEY (template_id) REFERENCES communication_email_templates(id),
    CONSTRAINT fk_communication_campaign_admin FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_campaign_recipients (
    campaign_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    status ENUM('pending','queued','sent','skipped','failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id, user_id),
    KEY idx_communication_campaign_recipient_status (campaign_id, status, user_id),
    CONSTRAINT fk_communication_campaign_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES communication_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_communication_campaign_recipient_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_mail_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    transport ENUM('smtp') NOT NULL DEFAULT 'smtp',
    from_address VARCHAR(254) NOT NULL,
    from_name VARCHAR(120) NOT NULL DEFAULT 'ClipForge',
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port SMALLINT UNSIGNED NOT NULL,
    smtp_encryption ENUM('tls','ssl') NOT NULL,
    smtp_username VARCHAR(254) NULL,
    smtp_password_ciphertext MEDIUMTEXT NULL,
    smtp_timeout SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    last_test_status ENUM('untested','success','failed') NOT NULL DEFAULT 'untested',
    last_test_code VARCHAR(64) NULL,
    last_tested_at DATETIME NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_communication_mail_admin FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
