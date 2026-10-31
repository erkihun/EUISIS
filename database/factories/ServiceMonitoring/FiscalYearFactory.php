<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;

/** @extends Factory<FiscalYear> */
class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ec_year' => 2019,
            'starts_on' => '2026-07-08',
            'ends_on' => '2027-07-07',
            'status' => 'active',
        ];
    }
}
