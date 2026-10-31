<?php

use App\Providers\AppServiceProvider;
use App\Providers\BackboneServiceProvider;
use App\Providers\IntelligenceServiceProvider;
use App\Providers\ServiceModuleServiceProvider;
use Modules\Assessment\Providers\AssessmentServiceProvider;
use Modules\Cafeteria\Providers\CafeteriaServiceProvider;
use Modules\Communication\Providers\CommunicationServiceProvider;
use Modules\DailyWork\Providers\DailyWorkServiceProvider;
use Modules\Discipline\Providers\DisciplineServiceProvider;
use Modules\Entitlements\Providers\EntitlementsServiceProvider;
use Modules\Feedback\Providers\FeedbackServiceProvider;
use Modules\FieldWork\Providers\FieldWorkServiceProvider;
use Modules\Grievance\Providers\GrievanceServiceProvider;
use Modules\Performance\Providers\PerformanceServiceProvider;
use Modules\Providers\Providers\ProvidersServiceProvider;
use Modules\PublicSite\Providers\PublicSiteServiceProvider;
use Modules\Reception\Providers\ReceptionServiceProvider;
use Modules\ServiceMonitoring\Providers\ServiceMonitoringServiceProvider;
use Modules\Transport\Providers\TransportServiceProvider;

return [
    AppServiceProvider::class,
    BackboneServiceProvider::class,
    // After the backbone: fills its registries and contract ports.
    ServiceModuleServiceProvider::class,
    EntitlementsServiceProvider::class,
    IntelligenceServiceProvider::class,
    // Business modules under Modules/ (ADR 0017): each wires only itself.
    ProvidersServiceProvider::class,
    CafeteriaServiceProvider::class,
    TransportServiceProvider::class,
    DailyWorkServiceProvider::class,
    CommunicationServiceProvider::class,
    AssessmentServiceProvider::class,
    PerformanceServiceProvider::class,
    GrievanceServiceProvider::class,
    DisciplineServiceProvider::class,
    FieldWorkServiceProvider::class,
    ServiceMonitoringServiceProvider::class,
    ReceptionServiceProvider::class,
    FeedbackServiceProvider::class,
    PublicSiteServiceProvider::class,
];
