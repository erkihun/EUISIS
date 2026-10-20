<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

/**
 * The transport switches an administrator sets on Transport › Settings
 * (system_settings group `transport`), read by the settings page and
 * enforced by the boarding engine, the device API and the settlement engine.
 *
 * Every default is the safe one: a boarding needs a trip, a second boarding
 * on the same leg is refused, capacity blocks, an alternative route is
 * refused, offline boarding is off until the city approves it
 * (NEEDS_POLICY_DECISION_OFFLINE_BOARDING).
 */
final class TransportSettings
{
    public const DUPLICATE_ONE_PER_TRIP = 'one_per_trip';

    public const DUPLICATE_ONE_PER_DIRECTION_PER_DAY = 'one_per_direction_per_day';

    public const CAPACITY_BLOCK = 'block';

    public const CAPACITY_WARN = 'warn';

    public const ALTERNATIVE_ROUTE_DENY = 'deny';

    public const ALTERNATIVE_ROUTE_SAME_PROVIDER = 'allow_same_provider';

    public const ALTERNATIVE_ROUTE_ANY_ELIGIBLE = 'allow_any_eligible';

    /** @var array<string, bool|int|string> */
    public const DEFAULTS = [
        // Eligibility
        'require_pass_for_scan' => true,
        'allow_pay_as_you_go' => false,
        'organization_grants_board_without_pass' => true,
        'alternative_route_policy' => self::ALTERNATIVE_ROUTE_DENY,
        // Verification
        'qr_enabled' => true,
        'nfc_enabled' => false,
        'scan_nonce_required' => true,
        'require_trip_for_boarding' => true,
        'duplicate_boarding_policy' => self::DUPLICATE_ONE_PER_DIRECTION_PER_DAY,
        'capacity_enforcement' => self::CAPACITY_BLOCK,
        'manual_exception_enabled' => true,
        'manual_exceptions_alert_threshold' => 5,
        // Devices and offline
        'offline_enabled' => false,
        'offline_grace_minutes' => 240,
        'device_inactivity_timeout_minutes' => 1440,
        // Settlement
        'settlement_requires_distinct_approver' => true,
        // Retention (NEEDS_POLICY_DECISION_TRANSPORT_RETENTION: the default keeps everything)
        'gps_retention_days' => 90,
    ];

    /** @var array<string, list<string>> keys with a fixed vocabulary */
    public const CHOICES = [
        'alternative_route_policy' => [self::ALTERNATIVE_ROUTE_DENY, self::ALTERNATIVE_ROUTE_SAME_PROVIDER, self::ALTERNATIVE_ROUTE_ANY_ELIGIBLE],
        'duplicate_boarding_policy' => [self::DUPLICATE_ONE_PER_TRIP, self::DUPLICATE_ONE_PER_DIRECTION_PER_DAY],
        'capacity_enforcement' => [self::CAPACITY_BLOCK, self::CAPACITY_WARN],
    ];

    /**
     * Memoised in the cache store, not on the instance: a controller (and its
     * injected services) lives for the whole process under Octane and across
     * requests in the test runner, so an instance memo would serve the first
     * read forever. The short TTL also picks up edits made on the generic
     * System Settings page; Transport › Settings forgets the key at once.
     */
    public const CACHE_KEY = 'transport_settings_all';

    private const CACHE_SECONDS = 60;

    /** @return array<string, bool|int|string> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $rows = SystemSetting::query()
                ->where('group', 'transport')
                ->whereIn('key', array_keys(self::DEFAULTS))
                ->get()
                ->keyBy('key');

            return collect(self::DEFAULTS)
                ->map(function (bool|int|string $default, string $key) use ($rows): bool|int|string {
                    $stored = $rows->get($key)?->typedValue();

                    return self::normalise($key, $stored ?? $default, $default);
                })
                ->all();
        });
    }

    public static function typeOf(string $key): string
    {
        $default = self::DEFAULTS[$key];

        return match (true) {
            is_bool($default) => 'boolean',
            is_int($default) => 'integer',
            isset(self::CHOICES[$key]) => 'select',
            default => 'string',
        };
    }

    /** A stored value coerced to the key's type; an unknown choice falls back to the default. */
    public static function normalise(string $key, mixed $value, bool|int|string $default): bool|int|string
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
        }
        if (is_int($default)) {
            return is_numeric($value) ? max(0, (int) $value) : $default;
        }
        $value = is_scalar($value) ? (string) $value : $default;

        return isset(self::CHOICES[$key]) && ! in_array($value, self::CHOICES[$key], true) ? $default : $value;
    }

    public function requirePass(): bool
    {
        return (bool) $this->all()['require_pass_for_scan'];
    }

    public function allowPayAsYouGo(): bool
    {
        return (bool) $this->all()['allow_pay_as_you_go'];
    }

    public function organizationGrantsBoardWithoutPass(): bool
    {
        return (bool) $this->all()['organization_grants_board_without_pass'];
    }

    public function alternativeRoutePolicy(): string
    {
        return (string) $this->all()['alternative_route_policy'];
    }

    public function qrEnabled(): bool
    {
        return (bool) $this->all()['qr_enabled'];
    }

    public function nfcEnabled(): bool
    {
        return (bool) $this->all()['nfc_enabled'];
    }

    public function nonceRequired(): bool
    {
        return (bool) $this->all()['scan_nonce_required'];
    }

    public function requireTripForBoarding(): bool
    {
        return (bool) $this->all()['require_trip_for_boarding'];
    }

    public function duplicatePolicy(): string
    {
        return (string) $this->all()['duplicate_boarding_policy'];
    }

    public function capacityBlocks(): bool
    {
        return $this->all()['capacity_enforcement'] === self::CAPACITY_BLOCK;
    }

    public function manualExceptionEnabled(): bool
    {
        return (bool) $this->all()['manual_exception_enabled'];
    }

    public function manualExceptionsAlertThreshold(): int
    {
        return (int) $this->all()['manual_exceptions_alert_threshold'];
    }

    public function offlineEnabled(): bool
    {
        return (bool) $this->all()['offline_enabled'];
    }

    public function offlineGraceMinutes(): int
    {
        return (int) $this->all()['offline_grace_minutes'];
    }

    public function deviceInactivityTimeoutMinutes(): int
    {
        return (int) $this->all()['device_inactivity_timeout_minutes'];
    }

    public function settlementRequiresDistinctApprover(): bool
    {
        return (bool) $this->all()['settlement_requires_distinct_approver'];
    }

    public function gpsRetentionDays(): int
    {
        return (int) $this->all()['gps_retention_days'];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
