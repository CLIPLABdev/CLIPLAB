ALTER TABLE plans
    ADD COLUMN description VARCHAR(500) NOT NULL DEFAULT '' AFTER name,
    ADD COLUMN daily_credits INT UNSIGNED NOT NULL DEFAULT 0 AFTER credits;

ALTER TABLE users
    ADD COLUMN daily_credits_granted_on DATE NULL AFTER credits;

UPDATE plans SET slug = 'iniciante' WHERE slug = 'pro' AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM plans WHERE slug = 'iniciante') existing);

UPDATE plans SET slug = 'louco' WHERE slug = 'business' AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM plans WHERE slug = 'louco') existing);

UPDATE plans SET
    name = 'Cientista Free',
    price_cents = 0, monthly_minutes = 300, credits = 10,
    features = '{"exports_hd":false,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":524288000,"storage_bytes":2147483648}}'
WHERE slug = 'free';

UPDATE plans SET
    name = 'Cientista Iniciante',
    price_cents = 0, monthly_minutes = 750, credits = 25,
    features = '{"exports_hd":true,"priority_processing":false,"team_access":false,"limits":{"max_upload_bytes":1073741824,"storage_bytes":10737418240}}'
WHERE slug = 'iniciante';

UPDATE plans SET
    name = 'Cientista Louco',
    price_cents = 0, monthly_minutes = 1500, credits = 50,
    features = '{"exports_hd":true,"priority_processing":true,"team_access":false,"limits":{"max_upload_bytes":3221225472,"storage_bytes":32212254720}}'
WHERE slug = 'louco';
