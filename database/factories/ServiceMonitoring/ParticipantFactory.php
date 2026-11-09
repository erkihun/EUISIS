<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** @extends Factory<Participant> */
class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->create(['code' => 'SMP-'.fake()->unique()->numerify('#####'), 'name_en' => 'Institution '.fake()->unique()->numerify('###'), 'status' => 'active'])->id,
            'level' => 'center',
            'active_from' => '2026-07-08',
        ];
    }
}
