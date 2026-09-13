ALTER TABLE users ADD COLUMN archived_at DATETIME NULL AFTER status;
ALTER TABLE users ADD KEY idx_users_archived_at (archived_at);

CREATE TABLE platform_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE promotions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body VARCHAR(500) NOT NULL,
    cta_label VARCHAR(60) NULL,
    cta_url VARCHAR(255) NULL,
    image_url VARCHAR(255) NULL,
    delivery_kind ENUM('banner','popup','notice') NOT NULL DEFAULT 'banner',
    placement ENUM('dashboard','projects','account') NOT NULL DEFAULT 'dashboard',
    audience ENUM('all','user','plan') NOT NULL DEFAULT 'all',
    plan_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_promotions_visibility (is_active, placement, starts_at, ends_at),
    KEY idx_promotions_user (user_id),
    KEY idx_promotions_plan (plan_id),
    CONSTRAINT fk_promotions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_promotions_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT,
    CONSTRAINT chk_promotions_audience CHECK ((audience = 'all' AND user_id IS NULL AND plan_id IS NULL) OR (audience = 'user' AND user_id IS NOT NULL AND plan_id IS NULL) OR (audience = 'plan' AND plan_id IS NOT NULL AND user_id IS NULL)),
    CONSTRAINT chk_promotions_schedule CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
