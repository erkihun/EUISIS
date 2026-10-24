<?php

use App\Providers\AppServiceProvider;
use App\Providers\BackboneServiceProvider;
use App\Providers\IntelligenceServiceProvider;
use App\Providers\ServiceModuleServiceProvider;

return [
    AppServiceProvider::class,
    BackboneServiceProvider::class,
    // After the backbone: fills its registries and contract ports.
    ServiceModuleServiceProvider::class,
    IntelligenceServiceProvider::class,
];
