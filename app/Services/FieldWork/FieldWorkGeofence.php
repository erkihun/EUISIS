<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Enums\FieldWorkLocationValidation;
use App\Models\FieldWorkRequest;

/**
 * Server-side destination check for one GPS capture. The browser's own
 * distance or verdict is never accepted; only raw latitude, longitude and
 * reported accuracy come from the device.
 */
class FieldWorkGeofence
{
    private const EARTH_RADIUS_M = 6371008.8;

    public function __construct(private readonly FieldWorkSettings $settings) {}

    /** @return array{distance_m: ?float, status: FieldWorkLocationValidation} */
    public function evaluate(FieldWorkRequest $request, float $latitude, float $longitude, ?float $accuracy): array
    {
        if (! $request->hasGeofence()) {
            return ['distance_m' => null, 'status' => FieldWorkLocationValidation::CannotValidate];
        }

        $distance = round($this->distanceMeters((float) $request->expected_latitude, (float) $request->expected_longitude, $latitude, $longitude), 2);

        // Without a credible accuracy the position cannot prove presence
        // either way, so it is neither "within" nor "outside".
        if ($accuracy === null || $accuracy > $this->settings->maxAccuracyMeters()) {
            return ['distance_m' => $distance, 'status' => FieldWorkLocationValidation::LowAccuracy];
        }

        return [
            'distance_m' => $distance,
            'status' => $distance <= (float) $request->geofence_radius_m
                ? FieldWorkLocationValidation::WithinExpectedArea
                : FieldWorkLocationValidation::OutsideExpectedArea,
        ];
    }

    /** Great-circle (haversine) distance in metres. */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * atan2(sqrt($a), sqrt(1 - $a));
    }
}
