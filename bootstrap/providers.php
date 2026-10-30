<?php

use App\Providers\AppServiceProvider;
use App\Providers\BackboneServiceProvider;
use App\Providers\IntelligenceServiceProvider;
use App\Providers\ServiceModuleServiceProvider;
use Modules\Cafeteria\Providers\CafeteriaServiceProvider;
use Modules\Transport\Providers\TransportServiceProvider;

return [
    AppServiceProvider::class,
    BackboneServiceProvider::class,
    // After the backbone: fills its registries and contract ports.
    ServiceModuleServiceProvider::class,
    IntelligenceServiceProvider::class,
    // Business modules under Modules/ (ADR 0017): each wires only itself.
    CafeteriaServiceProvider::class,
    TransportServiceProvider::class,
];
