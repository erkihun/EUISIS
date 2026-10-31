<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReminderLog;

/** @extends Factory<ReminderLog> */
class ReminderLogFactory extends Factory
{
    protected $model = ReminderLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'type' => 'due_today',
            'sent_on' => now()->toDateString(),
            'subject_key' => (string) Str::uuid7(),
        ];
    }
}
