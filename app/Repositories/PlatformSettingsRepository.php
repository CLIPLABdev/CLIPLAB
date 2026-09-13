<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PlatformSettingsRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string,string> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM platform_settings')->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $row) $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        return $settings;
    }

    /** @param array<string,string> $values */
    public function replace(array $values): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $mysql
            ? 'INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP';
        $statement = $this->pdo->prepare($sql);
        foreach ($values as $key => $value) $statement->execute(['key' => $key, 'value' => $value]);
    }
}
