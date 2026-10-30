<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<Submission> */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'period_id' => ReportingPeriod::factory(),
            'participant_id' => Participant::factory(),
            'organization_id' => fn (array $attributes): string => (string) Participant::query()->whereKey($attributes['participant_id'])->value('organization_id'),
            'level' => 'center',
            'form_type' => 'A',
            'form_version' => 'fy2019',
            'revision_no' => 1,
            'is_current' => true,
            'status' => 'draft',
        ];
    }
}
