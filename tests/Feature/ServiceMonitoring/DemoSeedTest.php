<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/* The demo dataset (docs/demo-seed-data.md) includes a service-monitoring sample and stays idempotent. */

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Freezes the clock at an Addis date and time. */
function smDemoClock(string $at): void
{
    $moment = CarbonImmutable::parse($at, 'Africa/Addis_Ababa')->utc();
    Carbon::setTestNow($moment);
    CarbonImmutable::setTestNow($moment);
}

it('seeds FY 2019, a sub-city with two woredas and a center bureau, and reports in mixed states', function (): void {
    Notification::fake();
    smDemoClock('2026-12-15 10:00');
    $this->seed(DemoDataSeeder::class);

    expect(Participant::query()->pluck('level')->map->value->sort()->values()->all())->toBe(['center', 'sub_city', 'woreda', 'woreda'])
        ->and(Submission::query()->pluck('status')->map->value->sort()->values()->all())->toBe(['accepted', 'accepted', 'draft', 'returned', 'submitted']);

    $this->seed(DemoDataSeeder::class);
    expect(Submission::query()->count())->toBe(5)->and(Participant::query()->count())->toBe(4);
});

it('dates nothing in the future: before the second quarter opens there is no draft for it', function (): void {
    Notification::fake();
    smDemoClock('2026-11-01 10:00');
    $this->seed(DemoDataSeeder::class);

    expect(Submission::query()->pluck('status')->map->value->sort()->values()->all())->toBe(['accepted', 'accepted', 'returned', 'submitted'])
        ->and(Submission::query()->where('created_at', '>', now())->exists())->toBeFalse();
});
