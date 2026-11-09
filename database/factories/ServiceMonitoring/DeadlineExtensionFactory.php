<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\DeadlineExtension;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** @extends Factory<DeadlineExtension> */
class DeadlineExtensionFactory extends Factory
{
    protected $model = DeadlineExtension::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'period_id' => ReportingPeriod::factory(),
            'participant_id' => Participant::factory(),
            'due_at' => now()->addDays(3),
            'reason' => 'Network outage at the woreda for the whole week.',
        ];
    }
}
