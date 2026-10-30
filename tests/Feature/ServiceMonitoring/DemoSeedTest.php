<?php

declare(strict_types=1);

use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Notification;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/* The demo dataset (docs/demo-seed-data.md) includes a service-monitoring sample and stays idempotent. */

it('seeds FY 2019, a sub-city with two woredas and a center bureau, and reports in mixed states', function (): void {
    Notification::fake();
    $this->seed(DemoDataSeeder::class);

    expect(Participant::query()->pluck('level')->map->value->sort()->values()->all())->toBe(['center', 'sub_city', 'woreda', 'woreda'])
        ->and(Submission::query()->pluck('status')->map->value->sort()->values()->all())->toBe(['accepted', 'accepted', 'draft', 'returned', 'submitted']);

    $this->seed(DemoDataSeeder::class);
    expect(Submission::query()->count())->toBe(5)->and(Participant::query()->count())->toBe(4);
});
