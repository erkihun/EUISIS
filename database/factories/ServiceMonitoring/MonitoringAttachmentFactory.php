<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;

/** @extends Factory<MonitoringAttachment> */
class MonitoringAttachmentFactory extends Factory
{
    protected $model = MonitoringAttachment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'attachable_type' => 'submission',
            'attachable_id' => (string) Str::uuid7(),
            'kind' => 'stamped_copy',
            'disk' => 'local',
            'path' => 'service-monitoring/'.Str::random(40).'.pdf',
            'original_name' => 'stamped.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'sha256' => hash('sha256', 'stamped'),
        ];
    }
}
