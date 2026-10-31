<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\SupportAssignment;

/** @extends Factory<SupportAssignment> */
class SupportAssignmentFactory extends Factory
{
    protected $model = SupportAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'user_id' => User::factory(),
        ];
    }
}
