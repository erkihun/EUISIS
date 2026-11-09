<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Contracts\Data;

/**
 * The Situation Room's service-monitoring figures (D26), all counts.
 *
 * @phpstan-type Compliance array{on_time: int, late: int, missing: int, in_progress: int}
 */
final readonly class ServiceMonitoringIndicatorSnapshot
{
    /**
     * @param  ?string  $periodLabelEn  the period the compliance figures describe
     * @param  array{on_time: int, late: int, missing: int, in_progress: int}  $compliance
     * @param  array<string, int>  $accountabilityByAction  final action => people (each counted once)
     * @param  array{main_above: int, main_at: int, main_below: int, sub_above: int, sub_at: int, sub_below: int}  $standardPerformance
     */
    public function __construct(
        public ?string $periodLabelEn,
        public ?string $periodLabelAm,
        public array $compliance,
        public array $accountabilityByAction,
        public int $digitalServicesVerified,
        public array $standardPerformance,
        public int $overdueActionItems,
    ) {}
}
