<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** @extends Factory<ReportingPeriod> */
class ReportingPeriodFactory extends Factory
{
    protected $model = ReportingPeriod::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'fiscal_year_id' => FiscalYear::factory(),
            'quarter' => 1,
            'form_version' => 'fy2019',
            'starts_on' => '2026-07-08',
            'ends_on' => '2026-10-10',
            'opens_on' => '2026-09-11',
            'submission_due_on' => '2026-10-20',
            'subcity_review_due_on' => '2026-10-25',
            'city_review_due_on' => '2026-10-30',
            'status' => 'open',
        ];
    }
}
