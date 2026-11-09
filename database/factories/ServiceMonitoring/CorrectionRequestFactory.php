<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\CorrectionRequest;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<CorrectionRequest> */
class CorrectionRequestFactory extends Factory
{
    protected $model = CorrectionRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'reason' => 'Two customers were left out of Section I by mistake.',
            'status' => 'pending',
        ];
    }
}
