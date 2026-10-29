<?php

declare(strict_types=1);

use App\Enums\CalendarSystem;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\EthiopianCalendarService;

// ─── Known date vectors ──────────────────────────────────────────────────────

// 2015 E.C. is a leap year (Y mod 4 = 3): Pagume 6, 2015 is 11 Sept 2023 and
// Ethiopian New Year 2016 is 12 Sept 2023; Genna (7 Jan 2024) is Tahsas 28.
dataset('gregorian_to_ethiopian', [
    'Sept 11 2023 = Pagume 6 2015' => [2023, 9, 11, 2015, 13, 6],
    'Sept 12 2023 = Meskerem 1 2016' => [2023, 9, 12, 2016, 1, 1],
    'Jan 7 2024 = Tahsas 28 2016' => [2024, 1,  7,  2016, 4, 28],
    'May 26 2026 = Ginbot 18 2018' => [2026, 5, 26, 2018, 9, 18],
    'Sept 11 2026 = Meskerem 1 2019' => [2026, 9, 11, 2019, 1, 1],
    'Apr 12 1988 = Miyazia 4 1980' => [1988, 4, 12, 1980, 8, 4],
]);

dataset('ethiopian_to_gregorian', [
    'Meskerem 1 2016 = Sept 12 2023' => [2016, 1, 1,  2023, 9, 12],
    'Tahsas 28 2016 = Jan 7 2024' => [2016, 4, 28, 2024, 1,  7],
    'Ginbot 18 2018 = May 26 2026' => [2018, 9, 18, 2026, 5, 26],
    'Miyazia 4 1980 = Apr 12 1988' => [1980, 8, 4, 1988, 4, 12],
]);

// ─── Conversion tests ─────────────────────────────────────────────────────────

test('gregorian to ethiopian', function (int $gy, int $gm, int $gd, int $ey, int $em, int $ed): void {
    $svc = new EthiopianCalendarService;
    $result = $svc->gregorianToEthiopian($gy, $gm, $gd);

    expect($result['year'])->toBe($ey)
        ->and($result['month'])->toBe($em)
        ->and($result['day'])->toBe($ed);
})->with('gregorian_to_ethiopian');

test('ethiopian to gregorian', function (int $ey, int $em, int $ed, int $gy, int $gm, int $gd): void {
    $svc = new EthiopianCalendarService;
    $result = $svc->ethiopianToGregorian($ey, $em, $ed);

    expect($result->year)->toBe($gy)
        ->and($result->month)->toBe($gm)
        ->and($result->day)->toBe($gd);
})->with('ethiopian_to_gregorian');

test('round-trip gregorian → ethiopian → gregorian', function (): void {
    $svc = new EthiopianCalendarService;

    $dates = [
        [2000, 1, 1], [2020, 2, 29], [2024, 9, 11], [2026, 5, 26],
    ];

    foreach ($dates as [$y, $m, $d]) {
        $eth = $svc->gregorianToEthiopian($y, $m, $d);
        $back = $svc->ethiopianToGregorian($eth['year'], $eth['month'], $eth['day']);

        expect($back->year)->toBe($y)
            ->and($back->month)->toBe($m)
            ->and($back->day)->toBe($d);
    }
});

// ─── Leap year tests ──────────────────────────────────────────────────────────

test('ethiopian leap year when year mod 4 === 3', function (): void {
    $svc = new EthiopianCalendarService;

    expect($svc->isEthiopianLeapYear(2011))->toBeTrue()   // 2011 % 4 = 3
        ->and($svc->isEthiopianLeapYear(2015))->toBeTrue() // 2015 % 4 = 3
        ->and($svc->isEthiopianLeapYear(2016))->toBeFalse()
        ->and($svc->isEthiopianLeapYear(2017))->toBeFalse()
        ->and($svc->isEthiopianLeapYear(2018))->toBeFalse()
        ->and($svc->isEthiopianLeapYear(2019))->toBeTrue(); // 2019 % 4 = 3
});

test('pagume has 5 days in non-leap year', function (): void {
    $svc = new EthiopianCalendarService;
    expect($svc->isValidEthiopianDate(2016, 13, 5))->toBeTrue()
        ->and($svc->isValidEthiopianDate(2016, 13, 6))->toBeFalse();
});

test('pagume has 6 days in leap year', function (): void {
    $svc = new EthiopianCalendarService;
    expect($svc->isValidEthiopianDate(2015, 13, 6))->toBeTrue()  // 2015 is leap
        ->and($svc->isValidEthiopianDate(2015, 13, 7))->toBeFalse();
});

test('regular ethiopian month has 30 days', function (): void {
    $svc = new EthiopianCalendarService;
    expect($svc->isValidEthiopianDate(2016, 1, 30))->toBeTrue()
        ->and($svc->isValidEthiopianDate(2016, 1, 31))->toBeFalse();
});

test('ethiopian date formatting can zero-pad the day for card faces', function (): void {
    $svc = new EthiopianCalendarService;

    expect($svc->formatEthiopianDate(2017, 1, 1, 'am', true))
        ->toBe('መስከረም 01, 2017');
});

// ─── Validation ──────────────────────────────────────────────────────────────

test('invalid ethiopian months are rejected', function (): void {
    $svc = new EthiopianCalendarService;
    expect($svc->isValidEthiopianDate(2016, 0, 1))->toBeFalse()
        ->and($svc->isValidEthiopianDate(2016, 14, 1))->toBeFalse();
});

test('invalid ethiopian days are rejected', function (): void {
    $svc = new EthiopianCalendarService;
    expect($svc->isValidEthiopianDate(2016, 5, 0))->toBeFalse();
});

test('ethiopianToGregorian throws on invalid date', function (): void {
    $svc = new EthiopianCalendarService;
    $svc->ethiopianToGregorian(2016, 13, 6); // non-leap year, day 6 = invalid
})->throws(InvalidArgumentException::class);

// ─── CalendarService locale routing ──────────────────────────────────────────

test('calendar service returns ethiopian for am locale', function (): void {
    $svc = new CalendarService(new EthiopianCalendarService);
    expect($svc->calendarSystemForLocale('am'))->toBe(CalendarSystem::Ethiopian);
});

test('calendar service returns gregorian for en locale', function (): void {
    $svc = new CalendarService(new EthiopianCalendarService);
    expect($svc->calendarSystemForLocale('en'))->toBe(CalendarSystem::Gregorian);
});

test('calendar service formatDate returns ethiopian for am locale', function (): void {
    $svc = new CalendarService(new EthiopianCalendarService);
    $display = $svc->formatDate('2026-05-26', 'am');
    // May 26 2026 = Ginbot 18 2018
    expect($display)->toContain('18')->toContain('2018');
});

test('calendar service formatDate returns formatted display string for en locale', function (): void {
    $svc = new CalendarService(new EthiopianCalendarService);
    expect($svc->formatDate('2026-05-26', 'en'))->toBe('May 26, 2026');
});

test('calendar service isValidGregorianIso accepts valid dates', function (): void {
    $svc = new CalendarService(new EthiopianCalendarService);
    expect($svc->isValidGregorianIso('2026-05-26'))->toBeTrue()
        ->and($svc->isValidGregorianIso('2024-02-29'))->toBeTrue();
});

test('calendar service isValidGregorianIso rejects bad dates', function (): void {
    $svc = new CalendarService(new EthiopianCalendarService);
    expect($svc->isValidGregorianIso('2023-02-29'))->toBeFalse() // non-leap
        ->and($svc->isValidGregorianIso('not-a-date'))->toBeFalse()
        ->and($svc->isValidGregorianIso('2024-13-01'))->toBeFalse();
});
