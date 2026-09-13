INSERT INTO plans (slug, name, price_cents, monthly_minutes, credits, features, is_active)
VALUES
    ('free', 'Free', 0, 30, 10, '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":104857600,"storage_bytes":1073741824}}', 1),
    ('pro', 'Pro', 1990, 300, 150, '{"exports_hd":true,"priority_processing":true,"team_access":false,"limits":{"max_upload_bytes":524288000,"storage_bytes":21474836480}}', 1),
    ('business', 'Business', 4990, 900, 500, '{"exports_hd":true,"priority_processing":true,"team_access":true,"limits":{"max_upload_bytes":524288000,"storage_bytes":107374182400}}', 1)
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug);
