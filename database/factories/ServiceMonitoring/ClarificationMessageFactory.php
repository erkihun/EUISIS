<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\Clarification;
use Modules\ServiceMonitoring\Domain\Models\ClarificationMessage;

/** @extends Factory<ClarificationMessage> */
class ClarificationMessageFactory extends Factory
{
    protected $model = ClarificationMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clarification_id' => Clarification::factory(),
            'body' => 'Only facilities organized this fiscal year count.',
        ];
    }
}
