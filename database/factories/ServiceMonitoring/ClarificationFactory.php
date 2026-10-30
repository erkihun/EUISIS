<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** @extends Factory<Clarification> */
class ClarificationFactory extends Factory
{
    protected $model = Clarification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'subject' => 'How do we count a newly opened day-care?',
            'status' => 'open',
        ];
    }
}
