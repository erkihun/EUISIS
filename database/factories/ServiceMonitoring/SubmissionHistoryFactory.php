<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Domain\Models\SubmissionHistory;

/** @extends Factory<SubmissionHistory> */
class SubmissionHistoryFactory extends Factory
{
    protected $model = SubmissionHistory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'from_status' => null,
            'to_status' => 'draft',
            'created_at' => now(),
        ];
    }
}
