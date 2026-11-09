<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\StandardPerformance;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<StandardPerformance> */
class StandardPerformanceFactory extends Factory
{
    protected $model = StandardPerformance::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'main_above' => 2,
            'main_at' => 8,
            'main_below' => 0,
            'sub_above' => 5,
            'sub_at' => 15,
            'sub_below' => 0,
        ];
    }
}
