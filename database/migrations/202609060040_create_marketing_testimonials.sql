CREATE TABLE IF NOT EXISTS marketing_testimonials (
    slot TINYINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    context VARCHAR(160) NOT NULL,
    quote VARCHAR(600) NOT NULL,
    result VARCHAR(160) NULL,
    source_url VARCHAR(255) NULL,
    authorization_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    published TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slot),
    KEY idx_marketing_testimonials_updated_by (updated_by),
    CONSTRAINT fk_marketing_testimonials_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
