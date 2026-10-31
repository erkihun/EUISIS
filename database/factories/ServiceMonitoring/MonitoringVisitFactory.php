<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** @extends Factory<MonitoringVisit> */
class MonitoringVisitFactory extends Factory
{
    protected $model = MonitoringVisit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'visited_on' => '2026-09-15',
            'purpose' => 'Check the customer waiting area and signage.',
            'status' => 'planned',
        ];
    }
}
