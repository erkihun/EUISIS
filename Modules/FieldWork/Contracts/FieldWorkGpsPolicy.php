<?php

declare(strict_types=1);

namespace Modules\FieldWork\Contracts;

/**
 * The GPS rules field work applies to a location (ADR 0017), for the map
 * configuration and the organization profile's geofence validation.
 */
interface FieldWorkGpsPolicy
{
    /** The largest geofence radius an organization may set, in metres. */
    public function maxGeofenceRadius(): int;
}
