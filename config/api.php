<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Organization structure document size
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/organizations/{organization}/structure returns one nested
    | document. Above this many nodes — positions, plus current occupants at
    | depth=employee — it answers 422 `structure_too_large` and names the
    | paginated /units, /positions and /employees endpoints instead, so a
    | large bureau never becomes a multi-megabyte response. depth=unit is
    | always served. See docs/api-organization-employee-v1.md §5.
    |
    */

    'structure_max_nodes' => (int) env('API_STRUCTURE_MAX_NODES', 10000),

];
