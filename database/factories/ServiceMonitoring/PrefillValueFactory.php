<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\PrefillValue;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<PrefillValue> */
class PrefillValueFactory extends Factory
{
    protected $model = PrefillValue::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'field' => 'employees_excl_drivers',
            'system_value' => 10,
            'source' => 'employee_assignments',
            'captured_at' => now(),
        ];
    }
}
