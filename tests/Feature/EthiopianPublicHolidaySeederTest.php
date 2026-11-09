<?php

declare(strict_types=1);

use App\Models\PublicHoliday;
use App\Services\Calendar\PublicHolidayCalendar;
use Database\Seeders\EthiopianPublicHolidaySeeder;
use Illuminate\Support\Carbon;

it('seeds the fixed national holidays once, recurring on their Ethiopian dates', function (): void {
    (new EthiopianPublicHolidaySeeder)->run();
    (new EthiopianPublicHolidaySeeder)->run();

    expect(PublicHoliday::query()->count())->toBe(8);

    $calendar = app(PublicHolidayCalendar::class);
    expect($calendar->isHoliday(Carbon::parse('2026-09-11')))->toBeTrue() // Enkutatash 2019 E.C.
        ->and($calendar->isHoliday(Carbon::parse('2026-09-27')))->toBeTrue() // Meskel
        ->and($calendar->isHoliday(Carbon::parse('2027-05-01')))->toBeTrue() // Labour Day
        ->and($calendar->isHoliday(Carbon::parse('2026-10-05')))->toBeFalse();
});
