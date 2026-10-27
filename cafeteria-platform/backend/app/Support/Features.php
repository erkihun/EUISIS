<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Module feature flags (config/features.php). Modules outside the current
 * production scope stay in the codebase but are unreachable while disabled.
 */
final class Features
{
    public static function enabled(string $module): bool
    {
        return (bool) config("features.{$module}", false);
    }

    /** The disabled module owning $routeName, or null when the route is live. */
    public static function disabledModuleForRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        foreach ((array) config('features.route_prefixes', []) as $module => $prefixes) {
            if (self::enabled($module)) {
                continue;
            }
            foreach ((array) $prefixes as $prefix) {
                if (str_starts_with($routeName, $prefix)) {
                    return $module;
                }
            }
        }

        return null;
    }

    /** False when $serviceCode belongs to a disabled module. */
    public static function serviceAvailable(string $serviceCode): bool
    {
        foreach ((array) config('features.service_codes', []) as $module => $codes) {
            if (in_array($serviceCode, (array) $codes, true) && ! self::enabled($module)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, bool> flags shared with the frontend */
    public static function shared(): array
    {
        return [
            'transport' => self::enabled('transport'),
            'transport_nfc' => self::enabled('transport') && self::enabled('transport_nfc'),
            'transport_offline' => self::enabled('transport') && self::enabled('transport_offline'),
            'transport_settlement' => self::enabled('transport') && self::enabled('transport_settlement'),
            'transport_gps' => self::enabled('transport') && self::enabled('transport_gps'),
            'court_cases' => self::enabled('court_cases'),
        ];
    }
}
