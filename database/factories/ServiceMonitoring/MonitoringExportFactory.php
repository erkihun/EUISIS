<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;

/** @extends Factory<MonitoringExport> */
class MonitoringExportFactory extends Factory
{
    protected $model = MonitoringExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'requested_by' => User::factory(),
            'scope_type' => 'city',
            'fiscal_year_id' => FiscalYear::factory(),
            'quarter' => 1,
            'range' => 'quarter',
            'format' => 'xlsx',
            'locale' => 'am',
            'status' => 'queued',
        ];
    }
}
