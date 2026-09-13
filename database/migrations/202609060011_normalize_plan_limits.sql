UPDATE plans
SET features = CONCAT(
    '{"exports_hd":',
    IF(JSON_UNQUOTE(JSON_EXTRACT(features, '$.exports_hd')) IN ('true', 'false'), JSON_UNQUOTE(JSON_EXTRACT(features, '$.exports_hd')), 'false'),
    ',"priority_processing":',
    IF(JSON_UNQUOTE(JSON_EXTRACT(features, '$.priority_processing')) IN ('true', 'false'), JSON_UNQUOTE(JSON_EXTRACT(features, '$.priority_processing')), 'false'),
    ',"team_access":',
    IF(JSON_UNQUOTE(JSON_EXTRACT(features, '$.team_access')) IN ('true', 'false'), JSON_UNQUOTE(JSON_EXTRACT(features, '$.team_access')), 'false'),
    ',"limits":{"max_upload_bytes":',
    IF(
        JSON_TYPE(JSON_EXTRACT(features, '$.limits.max_upload_bytes')) = 'INTEGER'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(features, '$.limits.max_upload_bytes')) AS SIGNED) > 0,
        JSON_UNQUOTE(JSON_EXTRACT(features, '$.limits.max_upload_bytes')),
        CASE WHEN slug IN ('pro', 'business') THEN '524288000' ELSE '104857600' END
    ),
    ',"storage_bytes":',
    IF(
        JSON_TYPE(JSON_EXTRACT(features, '$.limits.storage_bytes')) = 'INTEGER'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(features, '$.limits.storage_bytes')) AS SIGNED) > 0,
        JSON_UNQUOTE(JSON_EXTRACT(features, '$.limits.storage_bytes')),
        CASE slug WHEN 'pro' THEN '21474836480' WHEN 'business' THEN '107374182400' ELSE '1073741824' END
    ),
    '}}'
);
