<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** @extends Factory<DigitalService> */
class DigitalServiceFactory extends Factory
{
    protected $model = DigitalService::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'sequence' => 1,
            'level' => 'main',
            'service_name_am' => 'የንግድ ፈቃድ',
            'service_name_en' => 'Business licence',
            'is_catalog_gap' => true,
            'technology_type' => 'web_system',
            'system_name' => 'e-Licence',
            'system_count' => 1,
            'go_live_on' => '2026-08-01',
            'verification_status' => 'pending',
        ];
    }
}
