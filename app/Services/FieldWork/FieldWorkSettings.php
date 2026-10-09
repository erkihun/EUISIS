<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Carbon;

/**
 * Clock and tunables for Field Work. Timestamps are stored in the
 * application timezone (see storage()); the localization timezone
 * (Africa/Addis_Ababa by default) decides local dates and the wall-clock
 * times shown to users.
 */
class FieldWorkSettings
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function timezone(): string
    {
        $timezone = (string) $this->settings->get(SystemSettingsRegistry::GROUP_LOCALIZATION, 'timezone', 'Africa/Addis_Ababa');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Africa/Addis_Ababa';
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    public function today(): Carbon
    {
        return $this->now()->startOfDay();
    }

    /** A UTC instant as local wall-clock ISO ("Y-m-d\TH:i:s"), as the date components expect. */
    public function local(?Carbon $instant): ?string
    {
        return $instant?->copy()->setTimezone($this->timezone())->format('Y-m-d\TH:i:s');
    }

    /** Local date + "HH:MM" → instant, ready to store. */
    public function instant(string $date, string $time): Carbon
    {
        return $this->storage(Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $this->timezone())->startOfMinute());
    }

    /**
     * The same instant in the application timezone. Eloquent writes a Carbon
     * without converting it, and AppServiceProvider sets the PHP default
     * timezone from the localization setting at boot, so every instant that
     * is stored or compared in SQL must be expressed in that timezone.
     */
    public function storage(Carbon $instant): Carbon
    {
        return $instant->copy()->setTimezone(date_default_timezone_get());
    }

    public function maxAccuracyMeters(): float
    {
        return (float) config('field-work.gps.max_acceptable_accuracy_m', 100);
    }

    public function maxCaptureAgeMinutes(): int
    {
        return (int) config('field-work.gps.max_capture_age_minutes', 10);
    }

    public function maxFutureSkewMinutes(): int
    {
        return (int) config('field-work.gps.max_future_skew_minutes', 2);
    }

    public function blockOutsideExpectedArea(): bool
    {
        return (bool) config('field-work.gps.block_outside_expected_area', false);
    }

    public function maxGeofenceRadius(): int
    {
        return (int) config('field-work.gps.max_geofence_radius_m', 50000);
    }

    public function checkInEarlyMinutes(): int
    {
        return (int) config('field-work.check_in.early_minutes', 120);
    }

    public function maxParticipants(): int
    {
        return (int) config('field-work.participants.max', 30);
    }
}
