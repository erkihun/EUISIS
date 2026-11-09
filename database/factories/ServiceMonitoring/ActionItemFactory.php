<?php

declare(strict_types=1);

namespace Database\Factories\ServiceMonitoring;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ServiceMonitoring\Domain\Models\ActionItem;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;

/** @extends Factory<ActionItem> */
class ActionItemFactory extends Factory
{
    protected $model = ActionItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'visit_id' => MonitoringVisit::factory(),
            'title' => 'Install directional signage',
            'owner_organization_id' => fn (array $attributes): string => (string) MonitoringVisit::query()->whereKey($attributes['visit_id'])->first()?->participant?->organization_id,
            'due_on' => '2026-10-15',
            'status' => 'open',
        ];
    }
}
