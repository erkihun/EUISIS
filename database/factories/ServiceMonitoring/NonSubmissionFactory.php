<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\NonSubmission;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** @extends Factory<NonSubmission> */
class NonSubmissionFactory extends Factory
{
    protected $model = NonSubmission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'period_id' => ReportingPeriod::factory(),
            'participant_id' => Participant::factory(),
            'reason' => 'No report was received before the review deadline.',
        ];
    }
}
