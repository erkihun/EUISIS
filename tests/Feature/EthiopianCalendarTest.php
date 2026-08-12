<?php

declare(strict_types=1);

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\EthiopianCalendarService;
use App\Services\Calendar\LocalizedDateService;
use App\Support\EthiopianCalendar;
use Carbon\Carbon;

// ─── EthiopianCalendarService unit tests ─────────────────────────────────────

test('Ethiopian calendar converter correctly converts known date: 2024-09-11 is Ethiopian New Year 2017', function (): void {
    $service = app(EthiopianCalendarService::class);
    $eth = $service->gregorianToEthiopian(2024, 9, 11);

    expect($eth['year'])->toBe(2017);
    expect($eth['month'])->toBe(1);
    expect($eth['day'])->toBe(1);
});

test('Ethiopian calendar converts 2023-09-11 to Meskerem 1 2016 (documented test vector)', function (): void {
    $service = app(EthiopianCalendarService::class);
    $eth = $service->gregorianToEthiopian(2023, 9, 11);

    expect($eth['year'])->toBe(2016);
    expect($eth['month'])->toBe(1);
    expect($eth['day'])->toBe(1);
});

test('Ethiopian calendar converts 2024-01-07 to Tahsas 29, 2016 (documented test vector)', function (): void {
    $service = app(EthiopianCalendarService::class);
    $eth = $service->gregorianToEthiopian(2024, 1, 7);

    expect($eth['year'])->toBe(2016);
    expect($eth['month'])->toBe(4);
    expect($eth['day'])->toBe(29);
});

test('Ethiopian calendar converts Gregorian 2000-01-01 correctly (month 4 day 23)', function (): void {
    $service = app(EthiopianCalendarService::class);
    $eth = $service->gregorianToEthiopian(2000, 1, 1);

    // JDN epoch = 1724221; 2000-01-01 falls in Tahsas (month 4), day 23
    expect($eth['year'])->toBe(1992);
    expect($eth['month'])->toBe(4);
    expect($eth['day'])->toBe(23);
});

test('Ethiopian calendar round-trips: Gregorian → Ethiopian → Gregorian', function (): void {
    $service = app(EthiopianCalendarService::class);

    $original = Carbon::parse('2024-09-11');
    $eth = $service->gregorianToEthiopian($original->year, $original->month, $original->day);
    $roundTripped = $service->ethiopianToGregorian($eth['year'], $eth['month'], $eth['day']);

    expect($roundTripped->toDateString())->toBe($original->toDateString());
});

test('Ethiopian leap year: year mod 4 === 3 is a leap year', function (): void {
    $service = app(EthiopianCalendarService::class);

    expect($service->isEthiopianLeapYear(2015))->toBeTrue();  // 2015 % 4 === 3
    expect($service->isEthiopianLeapYear(2016))->toBeFalse();
    expect($service->isEthiopianLeapYear(2019))->toBeTrue();  // 2019 % 4 === 3
});

// ─── EthiopianCalendar static façade ─────────────────────────────────────────

test('EthiopianCalendar static toEthiopian works via support class', function (): void {
    $date = new DateTime('2024-09-11');
    $eth = EthiopianCalendar::toEthiopian($date);

    expect($eth['year'])->toBe(2017);
    expect($eth['month'])->toBe(1);
    expect($eth['day'])->toBe(1);
});

test('EthiopianCalendar static format returns dd/mm/yyyy for Ethiopian date', function (): void {
    $date = new DateTime('2024-09-11');
    $result = EthiopianCalendar::format($date, 'dd/mm/yyyy');

    expect($result)->toBe('01/01/2017');
});

test('EthiopianCalendar static format with MMM token includes Amharic month name', function (): void {
    $date = new DateTime('2024-09-11');
    $result = EthiopianCalendar::format($date, 'MMM dd, yyyy');

    expect($result)->toContain('2017');
    expect($result)->toContain('01');
    // Month 1 name is መስከረም
    expect($result)->toContain('መስከረም');
});

// ─── LocalizedDateService ────────────────────────────────────────────────────

test('LocalizedDateService displayDate returns named-month Ethiopian format for locale=am', function (): void {
    app()->setLocale('am');
    $service = app(LocalizedDateService::class);
    $date = Carbon::parse('2024-09-11');
    $display = $service->displayDate($date);

    // CalendarService formats as "{Amharic month name} {day}, {year}" for am locale
    expect($display)->toBe('መስከረም 1, 2017');
});

test('LocalizedDateService displayDate returns Gregorian long format for locale=en', function (): void {
    app()->setLocale('en');
    $service = app(LocalizedDateService::class);
    $date = Carbon::parse('2024-09-11');
    $display = $service->displayDate($date);

    // CalendarService uses format('F j, Y') for Gregorian
    expect($display)->toBe('September 11, 2024');
});

test('LocalizedDateService displayDate returns null for null input', function (): void {
    $service = app(LocalizedDateService::class);
    expect($service->displayDate(null))->toBeNull();
});

test('LocalizedDateService calendarSystemForLocale returns ethiopian for am', function (): void {
    $service = app(LocalizedDateService::class);
    expect($service->calendarSystemForLocale('am'))->toBe('ethiopian');
});

test('LocalizedDateService calendarSystemForLocale returns gregorian for en', function (): void {
    $service = app(LocalizedDateService::class);
    expect($service->calendarSystemForLocale('en'))->toBe('gregorian');
});

test('LocalizedDateService displayDate can be called with explicit locale override', function (): void {
    app()->setLocale('en'); // default is en
    $service = app(LocalizedDateService::class);
    $date = Carbon::parse('2024-09-11');

    // Override to Amharic
    $amDisplay = $service->displayDate($date, 'am');
    $enDisplay = $service->displayDate($date, 'en');

    expect($amDisplay)->toBe('መስከረም 1, 2017');
    expect($enDisplay)->toBe('September 11, 2024');
});

// ─── CalendarService ─────────────────────────────────────────────────────────

test('CalendarService formatDate locale=en produces Gregorian long-form date', function (): void {
    $service = app(CalendarService::class);
    $result = $service->formatDate('2024-09-11', 'en');

    expect($result)->toBe('September 11, 2024');
});

test('CalendarService formatDate locale=am produces Ethiopian named-month date', function (): void {
    $service = app(CalendarService::class);
    $result = $service->formatDate('2024-09-11', 'am');

    expect($result)->toBe('መስከረም 1, 2017');
});

test('CalendarService formatDate returns null for null input', function (): void {
    $service = app(CalendarService::class);
    expect($service->formatDate(null))->toBeNull();
    expect($service->formatDate(''))->toBeNull();
});

test('CalendarService toDatePayload includes raw Gregorian value and display value', function (): void {
    $service = app(CalendarService::class);
    $payload = $service->toDatePayload('2024-09-11', 'am');

    expect($payload['date_value'])->toBe('2024-09-11');
    expect($payload['date_display'])->toBe('መስከረም 1, 2017');
    expect($payload['calendar_system'])->toBe('ethiopian');
});

// ─── PHP lang files ───────────────────────────────────────────────────────────

test('EN PHP calendar lang file has required keys', function (): void {
    app()->setLocale('en');
    expect(__('calendar.gregorian_calendar'))->toBe('Gregorian Calendar');
    expect(__('calendar.ethiopian_calendar'))->toBe('Ethiopian Calendar');
    expect(__('calendar.effective_from'))->toBe('Effective From');
    expect(__('calendar.approved_at'))->toBe('Approved At');
});

test('AM PHP calendar lang file has required keys', function (): void {
    app()->setLocale('am');
    expect(__('calendar.gregorian_calendar'))->toBe('የጎርጎርዮሳዊ ቀን አቆጣጠር');
    expect(__('calendar.ethiopian_calendar'))->toBe('የኢትዮጵያ ዘመን አቆጣጠር');
    expect(__('calendar.effective_from'))->toBe('ከ');
    expect(__('calendar.approved_at'))->toBe('የጸደቀበት ቀን');
});

// ─── Database stores Gregorian ISO ───────────────────────────────────────────

test('HierarchyVersion stores and retrieves Gregorian ISO dates unchanged', function (): void {
    $isoDate = '2024-09-11';
    $version = HierarchyVersion::query()->create([
        'version_name' => 'calendar-test-'.str()->lower(str()->uuid()),
        'status' => HierarchyVersionStatus::Draft,
        'effective_from' => $isoDate,
    ]);

    // Reload from DB to confirm the raw stored value
    $version->refresh();

    expect($version->effective_from?->toDateString())->toBe($isoDate);
});

afterEach(function (): void {
    // Reset locale after each test so locale changes don't bleed across test suites
    app()->setLocale(config('app.locale', 'en'));
});
