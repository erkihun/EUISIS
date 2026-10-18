<?php

declare(strict_types=1);

/*
 * Map tiles for location pickers (field work expected location).
 *
 * The default is the public OpenStreetMap tile service, which asks for an
 * attribution and light use. A city tile server can replace it with
 * MAP_TILE_URL; its origin is added to the Content-Security-Policy img-src
 * (SecurityHeaders) and nothing else is loaded from it.
 */
return [
    'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'attribution' => env('MAP_TILE_ATTRIBUTION', '© OpenStreetMap contributors'),
    'max_zoom' => (int) env('MAP_MAX_ZOOM', 19),

    // Where an empty map opens: Addis Ababa.
    'default_center' => [
        'lat' => (float) env('MAP_DEFAULT_LAT', 9.0108),
        'lng' => (float) env('MAP_DEFAULT_LNG', 38.7613),
    ],
    'default_zoom' => (int) env('MAP_DEFAULT_ZOOM', 12),
];
