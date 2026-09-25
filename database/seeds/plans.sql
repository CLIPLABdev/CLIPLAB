INSERT INTO plans (slug, name, price_cents, monthly_minutes, credits, features, is_active)
VALUES
    ('free', 'Cientista Free', 0, 300, 10, '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":524288000,"storage_bytes":2147483648}}', 1),
    ('iniciante', 'Cientista Iniciante', 0, 750, 25, '{"exports_hd":true,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":1073741824,"storage_bytes":10737418240}}', 1),
    ('louco', 'Cientista Louco', 0, 1500, 50, '{"exports_hd":true,"priority_processing":true,"team_access":false,"limits":{"max_upload_bytes":3221225472,"storage_bytes":32212254720}}', 1),
    ('experiente', 'Cientista Experiente', 0, 3000, 100, '{"exports_hd":true,"priority_processing":true,"team_access":true,"limits":{"max_upload_bytes":3221225472,"storage_bytes":107374182400}}', 1)
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug);
