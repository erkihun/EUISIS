<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\InstitutionBuilding;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<InstitutionBuilding> */
class InstitutionBuildingFactory extends Factory
{
    protected $model = InstitutionBuilding::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'daycare_count' => 0,
            'cafeteria_count' => 0,
            'consumer_shop_count' => 0,
            'waiting_area_count' => 0,
            'waiting_area_capacity' => 0,
            'suggestion_box_count' => 0,
            'led_count' => 0,
            'signage_count' => 0,
            'employees_excl_drivers' => 0,
            'chest_badges_excl_drivers' => 0,
            'desk_badges' => 0,
            'awareness_trainings' => 0,
            'awareness_participants' => 0,
        ];
    }
}
