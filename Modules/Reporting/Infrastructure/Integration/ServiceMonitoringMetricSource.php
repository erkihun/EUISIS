<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Modules\Reporting\Application\Ports\MetricSource;
use Modules\ServiceMonitoring\Contracts\ServiceMonitoringIndicators;

/**
 * Optional indicators from Service Monitoring, read through its public
 * contract (counts only). Resolved lazily: when that module is switched off
 * or not bound, the source reports itself unavailable and Reporting goes on.
 */
final readonly class ServiceMonitoringMetricSource implements MetricSource
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'service_monitoring';
    }

    public function isAvailable(): bool
    {
        return (bool) config('features.service_monitoring', false) && $this->container->bound(ServiceMonitoringIndicators::class);
    }

    public function capture(array $organizationIds, string $periodStart, string $periodEnd): array
    {
        $snapshot = $this->container->make(ServiceMonitoringIndicators::class)
            ->snapshot($organizationIds, CarbonImmutable::parse($periodStart)->startOfDay(), CarbonImmutable::parse($periodEnd)->endOfDay());

        return [
            ['metric_key' => 'digital_services_verified', 'definition' => 'Digital services verified in the period (Service Monitoring, counts only)', 'value' => $snapshot->digitalServicesVerified, 'unit' => 'services'],
            ['metric_key' => 'overdue_action_items', 'definition' => 'Service-monitoring action items past their due date at capture time', 'value' => $snapshot->overdueActionItems, 'unit' => 'items'],
        ];
    }
}
