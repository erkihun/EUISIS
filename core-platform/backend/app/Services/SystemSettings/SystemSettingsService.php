<?php

declare(strict_types=1);

namespace App\Services\SystemSettings;

use App\Services\CoreSettings;
use App\Settings\CoreSettingsRegistry;
use App\Settings\Euisis\SystemSettingsRegistry as EuisisRegistry;

/**
 * What the copied EUISIS code (the ID card template designer) reads its
 * settings through: the Core setting when the Core has the key, else the
 * EUISIS default for it, else the caller's default.
 */
final class SystemSettingsService
{
    public function __construct(private readonly CoreSettings $settings) {}

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        if (CoreSettingsRegistry::definition($group, $key) !== null) {
            return $this->settings->get("{$group}.{$key}") ?? $default;
        }

        return EuisisRegistry::definition($group, $key)['default'] ?? $default;
    }
}
