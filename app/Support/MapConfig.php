<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\FieldWork\FieldWorkSettings;

/**
 * The map a location picker shows (config/maps.php) and the largest check-in
 * radius the rules accept. Shared by the field work form and the organization
 * forms, whose point and radius pre-fill field work sent to the organization.
 */
final class MapConfig
{
    /** @return array{tileUrl: string, attribution: string, maxZoom: int, center: array{lat: float, lng: float}, zoom: int, maxRadius: int} */
    public static function forPicker(): array
    {
        return [
            'tileUrl' => (string) config('maps.tile_url'),
            'attribution' => (string) config('maps.attribution'),
            'maxZoom' => (int) config('maps.max_zoom'),
            'center' => config('maps.default_center'),
            'zoom' => (int) config('maps.default_zoom'),
            'maxRadius' => app(FieldWorkSettings::class)->maxGeofenceRadius(),
        ];
    }
}
