<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\VisitFinding;

/** @extends Factory<VisitFinding> */
class VisitFindingFactory extends Factory
{
    protected $model = VisitFinding::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'visit_id' => MonitoringVisit::factory(),
            'section' => 2,
            'body' => 'No directional signage at the entrance.',
            'severity' => 'medium',
        ];
    }
}
