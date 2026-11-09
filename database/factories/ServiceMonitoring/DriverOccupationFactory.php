<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use App\Models\Occupation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\DriverOccupation;

/** @extends Factory<DriverOccupation> */
class DriverOccupationFactory extends Factory
{
    protected $model = DriverOccupation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'occupation_id' => Occupation::query()->create(['code' => 'DRV-'.fake()->unique()->numerify('####'), 'name_en' => 'Driver', 'is_active' => true])->id,
        ];
    }
}
