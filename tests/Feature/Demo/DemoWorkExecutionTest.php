<?php

declare(strict_types=1);

use App\Models\DailyActivityItem;
use Database\Seeders\DemoDataSeeder;

/*
 * The demo seeds the work execution register example: HR Officer →
 * Employee Administration → Employee Transfer Processing → Review Transfer
 * Application, standard 10 / 30 minutes / 100, and one measured execution
 * whose scores the server calculates.
 */
test('the demo records a measured task execution with server-calculated scores', function (): void {
    // A Tuesday after any real migration date: tracking starts on the day the
    // Daily Activity migration runs, and the demo needs an open working day.
    $this->travelTo('2030-01-08 10:00');
    $this->seed(DemoDataSeeder::class);

    $item = DailyActivityItem::query()->whereNotNull('task_standard_id')->firstOrFail();

    // 8 of 10 (80%), 30 planned / 40 taken (75%), 90 of 100 (90%).
    expect($item->duration_minutes)->toBe(40)
        ->and((string) $item->quantity_score)->toBe('80.0000')
        ->and((string) $item->time_score)->toBe('75.0000')
        ->and((string) $item->quality_score)->toBe('90.0000')
        ->and((string) $item->task_score)->toBe('81.6667')
        ->and($item->task->name_en)->toBe('DEMO Review Transfer Application')
        ->and($item->subService->name_en)->toBe('DEMO Employee Transfer Processing');
});
