<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\ConsolidationSnapshot;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** @extends Factory<ConsolidationSnapshot> */
class ConsolidationSnapshotFactory extends Factory
{
    protected $model = ConsolidationSnapshot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'period_id' => ReportingPeriod::factory(),
            'kind' => 'period_lock',
            'payload' => ['participants' => []],
            'checksum' => ConsolidationSnapshot::checksumOf(['participants' => []]),
            'created_at' => now(),
        ];
    }
}
