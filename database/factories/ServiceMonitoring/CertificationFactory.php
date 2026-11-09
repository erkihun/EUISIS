<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\Certification;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** @extends Factory<Certification> */
class CertificationFactory extends Factory
{
    protected $model = Certification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'period_id' => ReportingPeriod::factory(),
            'participant_id' => Participant::factory(),
            'certified_at' => now(),
        ];
    }
}
