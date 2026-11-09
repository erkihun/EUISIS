<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\ServiceTotals;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<ServiceTotals> */
class ServiceTotalsFactory extends Factory
{
    protected $model = ServiceTotals::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'main_total' => 10,
            'sub_total' => 20,
        ];
    }
}
