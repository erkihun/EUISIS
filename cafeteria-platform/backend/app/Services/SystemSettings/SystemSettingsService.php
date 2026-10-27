<?php

declare(strict_types=1);

namespace App\Services\SystemSettings;

use App\Services\CoreSettings;
use App\Settings\CoreSettingsRegistry;
use InvalidArgumentException;

/**
 * The EUISIS System Settings reader, as the copied cafeteria code calls it
 * (`get('security', 'max_login_attempts')`), answered by the platform's
 * settings store. A key the platform does not define answers the default.
 */
final class SystemSettingsService
{
    public function __construct(private readonly CoreSettings $settings) {}

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        try {
            return $this->settings->get($group.'.'.$key) ?? $default;
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    /** @return array<string, mixed> */
    public function getGroup(string $group): array
    {
        $values = [];
        foreach (array_keys(CoreSettingsRegistry::GROUPS[$group]['fields'] ?? []) as $key) {
            $values[$key] = $this->get($group, $key);
        }

        return $values;
    }

    public function clearCache(): void
    {
        $this->settings->clearCache();
    }
}
