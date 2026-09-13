CREATE TABLE billing_gateway_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL DEFAULT 'sandbox',
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    public_key VARCHAR(512) NULL,
    secret_ciphertext TEXT NULL,
    webhook_secret_ciphertext TEXT NULL,
    configuration_status ENUM('missing', 'configured', 'invalid') NOT NULL DEFAULT 'missing',
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_gateway_provider_environment (provider, environment),
    KEY idx_billing_gateway_active (is_active, environment),
    CONSTRAINT fk_billing_gateway_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_checkout_attempts (
    id VARCHAR(48) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL,
    request_key VARCHAR(100) NOT NULL,
    quote_json JSON NOT NULL,
    status ENUM('creating','pending','retry','ambiguous','confirmed','expired') NOT NULL,
    provider_checkout_id VARCHAR(191) NULL,
    provider_plan_id VARCHAR(191) NULL,
    checkout_url TEXT NULL,
    lease_until BIGINT NOT NULL DEFAULT 0,
    created_epoch BIGINT NOT NULL,
    failure_code VARCHAR(100) NULL,
    UNIQUE KEY uq_billing_checkout_request (user_id, request_key),
    UNIQUE KEY uq_billing_checkout_provider (provider, environment, provider_checkout_id),
    UNIQUE KEY uq_billing_checkout_plan (provider, environment, provider_plan_id),
    KEY idx_billing_checkout_user_status (user_id, status),
    CONSTRAINT fk_billing_checkout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_checkout_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL,
    provider_customer_id VARCHAR(191) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_customer_provider (provider, environment, provider_customer_id),
    UNIQUE KEY uq_billing_customer_user (user_id, provider, environment),
    CONSTRAINT fk_billing_customer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL,
    provider_subscription_id VARCHAR(191) NOT NULL,
    provider_customer_id VARCHAR(191) NOT NULL,
    checkout_attempt_id VARCHAR(48) NOT NULL,
    last_confirmed_epoch BIGINT NOT NULL DEFAULT 0,
    status ENUM('pending', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'incomplete') NOT NULL DEFAULT 'pending',
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    amount_cents INT UNSIGNED NOT NULL,
    interval_unit ENUM('month', 'year') NOT NULL DEFAULT 'month',
    interval_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    trial_ends_at DATETIME NULL,
    current_period_starts_at DATETIME NULL,
    current_period_ends_at DATETIME NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    canceled_at DATETIME NULL,
    plan_snapshot JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_subscription_provider (provider, environment, provider_subscription_id),
    KEY idx_billing_subscription_user_status (user_id, status),
    KEY idx_billing_subscription_period (current_period_ends_at),
    CONSTRAINT fk_billing_subscription_attempt FOREIGN KEY (checkout_attempt_id) REFERENCES billing_checkout_attempts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_subscription_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_subscription_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_entitlements (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    valid_until DATETIME NOT NULL,
    KEY idx_billing_entitlement_expiry (valid_until),
    CONSTRAINT fk_billing_entitlement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_entitlement_subscription FOREIGN KEY (subscription_id) REFERENCES billing_subscriptions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_entitlement_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL,
    provider_payment_id VARCHAR(191) NOT NULL,
    provider_invoice_id VARCHAR(191) NULL,
    status ENUM('pending', 'paid', 'failed', 'refunded', 'canceled') NOT NULL DEFAULT 'pending',
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    gross_amount_cents INT UNSIGNED NOT NULL,
    discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    paid_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    refunded_amount_cents INT UNSIGNED NOT NULL DEFAULT 0,
    period_ends_at DATETIME NULL,
    paid_at DATETIME NULL,
    failure_code VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_payment_provider (provider, environment, provider_payment_id),
    KEY idx_billing_payment_reporting (status, paid_at, provider),
    KEY idx_billing_payment_user (user_id, created_at),
    CONSTRAINT fk_billing_payment_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_billing_payment_subscription FOREIGN KEY (subscription_id) REFERENCES billing_subscriptions (id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_payment_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('sandbox','production') NOT NULL,
    provider_charge_id VARCHAR(191) NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    confirmed_at DATETIME NOT NULL,
    UNIQUE KEY uq_billing_refund_charge (provider,environment,provider_charge_id),
    CONSTRAINT fk_billing_refund_payment FOREIGN KEY (payment_id) REFERENCES billing_payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE billing_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('sandbox', 'production') NOT NULL,
    provider_event_id VARCHAR(191) NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    status ENUM('received', 'processed', 'rejected', 'retry') NOT NULL DEFAULT 'received',
    received_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    failure_code VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_webhook_provider_event (provider, environment, provider_event_id),
    KEY idx_billing_webhook_status (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    discount_type ENUM('percent', 'fixed') NOT NULL,
    discount_value INT UNSIGNED NOT NULL,
    currency CHAR(3) NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    max_redemptions INT UNSIGNED NULL,
    per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupon_code (code),
    KEY idx_coupon_available (is_active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_plans (
    coupon_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (coupon_id, plan_id),
    CONSTRAINT fk_coupon_plan_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_plan_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_redemptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    checkout_attempt_id VARCHAR(48) NOT NULL,
    coupon_code VARCHAR(80) NOT NULL,
    price_snapshot JSON NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    discount_cents INT UNSIGNED NOT NULL,
    status ENUM('reserved', 'applied', 'released') NOT NULL DEFAULT 'reserved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL,
    released_at DATETIME NULL,
    KEY idx_coupon_redemption_coupon (coupon_id, status),
    KEY idx_coupon_redemption_user (user_id, coupon_id, status),
    UNIQUE KEY uq_coupon_redemption_payment (payment_id),
    UNIQUE KEY uq_coupon_redemption_attempt (checkout_attempt_id),
    CONSTRAINT fk_coupon_redemption_attempt FOREIGN KEY (checkout_attempt_id) REFERENCES billing_checkout_attempts (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coupon_redemption_coupon FOREIGN KEY (coupon_id) REFERENCES coupons (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coupon_redemption_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coupon_redemption_payment FOREIGN KEY (payment_id) REFERENCES billing_payments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
