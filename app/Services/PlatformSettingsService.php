<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PlatformSettingsRepository;
use InvalidArgumentException;

final class PlatformSettingsService
{
    public function __construct(private PlatformSettingsRepository $settings) {}

    /** @return array{name:string,description:string,logo_url:?string,favicon_url:?string} */
    public function branding(): array
    {
        $values = $this->settings->all();
        return ['name' => $values['name'] ?? 'ClipForge', 'description' => $values['description'] ?? '', 'logo_url' => ($values['logo_url'] ?? '') ?: null, 'favicon_url' => ($values['favicon_url'] ?? '') ?: null];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 255) throw new InvalidArgumentException('Platform identity is invalid.');
        $logo = $this->asset($input['logo_url'] ?? null);
        $favicon = $this->asset($input['favicon_url'] ?? null);
        $this->settings->replace(['name' => $name, 'description' => $description, 'logo_url' => $logo ?? '', 'favicon_url' => $favicon ?? '']);
    }

    private function asset(mixed $value): ?string
    {
        if ($value===null) return null;
        if (!is_string($value)) throw new InvalidArgumentException('Only local raster assets are allowed.');
        $value = trim($value);
        if ($value === '') return null;
        if (preg_match('#\A/assets/images/[a-z0-9][a-z0-9._-]{0,120}\.(?:png|jpe?g|webp|ico)\z#iD', $value) !== 1) throw new InvalidArgumentException('Only local raster assets are allowed.');
        return $value;
    }
}
