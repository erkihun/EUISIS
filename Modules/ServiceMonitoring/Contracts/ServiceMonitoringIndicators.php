<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Contracts;

use Carbon\CarbonImmutable;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringIndicatorSnapshot;

/**
 * Aggregated, unnamed service-monitoring figures for the Situation Room's
 * service_monitoring category (D26). Counts only: no names, reasons or
 * individual reports.
 */
interface ServiceMonitoringIndicators
{
    /**
     * @param  list<string>|null  $organizationIds  the viewer's scope; null = city-wide
     */
    public function snapshot(?array $organizationIds, CarbonImmutable $from, CarbonImmutable $to): ServiceMonitoringIndicatorSnapshot;
}
