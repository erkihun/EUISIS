<?php

declare(strict_types=1);

use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\CafeteriaDayRule;
use App\Models\CafeteriaProvider;
use App\Models\CafeteriaSpecialDay;
use App\Models\CafeteriaSubsidyRule;
use App\Models\CafeteriaTransaction;
use App\Models\Employee;
use App\Models\EmployeeCafeteriaExclusion;
use App\Models\IdCard;
use App\Models\PublicHoliday;
use App\Models\ServiceProvider;
use App\Models\ServiceType;
use App\Services\Cafeteria\CafeteriaCalendarService;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Cafeteria\CafeteriaSettingsService;
use App\Services\Cafeteria\CafeteriaWeekWindowService;
use App\Services\Cafeteria\WorkingDayCalendarService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

function operationalScanFixture(): array
{
    $serviceType = ServiceType::query()->firstOrCreate(['code' => 'cafeteria'], ['name_en' => 'Cafeteria', 'is_active' => true]);
    $serviceProvider = ServiceProvider::query()->create(['service_type_id' => $serviceType->id, 'name' => 'Test cafeteria', 'code' => 'SP-'.Str::random(8), 'status' => 'active']);
    $employee = Employee::query()->create(['employee_number' => 'SET-'.Str::random(8), 'first_name' => 'Test', 'last_name' => 'Employee', 'full_name' => 'Test Employee', 'status' => EmployeeStatus::Active]);
    $card = IdCard::query()->create(['employee_id' => $employee->id, 'card_number' => 'CARD-'.Str::random(8), 'status' => CardStatus::Active, 'expires_at' => now()->addYear(), 'activated_at' => now(), 'is_current' => true, 'qr_status' => 'active', 'public_card_uuid' => Str::uuid()]);
    $provider = CafeteriaProvider::query()->create(['service_provider_id' => $serviceProvider->id, 'code' => 'CAF-'.Str::random(8), 'name_en' => 'Test cafeteria', 'is_active' => true]);
    CafeteriaSubsidyRule::query()->create(['code' => 'RULE-'.Str::random(8), 'name_en' => 'Test subsidy', 'subsidy_amount' => 40, 'currency' => 'ETB', 'effective_from' => '2026-01-01', 'applies_to' => 'all_employees', 'is_active' => true]);

    return [$employee, $card, $provider];
}

test('weekend fallback honors closure day flags and scan mode', function (): void {
    $settings = app(CafeteriaSettingsService::class);
    $calendar = app(WorkingDayCalendarService::class);
    $saturday = Carbon::parse('2026-09-26');
    expect($calendar->isCafeteriaOpen($saturday))->toBeFalse();
    $settings->setMany(['closed_weekend_default' => false, 'allow_saturday_service' => true, 'weekend_scan_mode' => 'allow']);
    expect($calendar->isCafeteriaOpen($saturday))->toBeTrue()->and($calendar->isSubsidyDay($saturday))->toBeTrue();
    expect($calendar->isCafeteriaOpen($saturday->copy()->addDay()))->toBeFalse();
    $settings->set('weekend_scan_mode', 'employee_payable');
    expect($calendar->isCafeteriaOpen($saturday))->toBeTrue()->and($calendar->isSubsidyDay($saturday))->toBeFalse();
    $settings->set('weekend_scan_mode', 'reject');
    expect($calendar->isCafeteriaOpen($saturday))->toBeFalse();
});

test('holiday subsidy requires allowance and exclusion to be disabled', function (): void {
    PublicHoliday::query()->create(['name_en' => 'Test holiday', 'holiday_date' => '2026-09-23', 'is_active' => true]);
    $date = Carbon::parse('2026-09-23');
    $calendar = app(WorkingDayCalendarService::class);
    $settings = app(CafeteriaSettingsService::class);
    expect($calendar->isCafeteriaOpen($date))->toBeFalse();
    $settings->set('holiday_scan_mode', 'allow');
    expect($calendar->isCafeteriaOpen($date))->toBeTrue()->and($calendar->isSubsidyDay($date))->toBeFalse();
    $settings->set('exclude_public_holidays', false);
    expect($calendar->isSubsidyDay($date))->toBeTrue();
    $settings->set('holiday_scan_mode', 'employee_payable');
    expect($calendar->isSubsidyDay($date))->toBeFalse();
});

test('configured weeks wrap correctly and never borrow the next window', function (): void {
    app(CafeteriaSettingsService::class)->setMany(['week_start_day' => 'friday', 'week_end_day' => 'tuesday']);
    $window = app(CafeteriaWeekWindowService::class);
    $monday = Carbon::parse('2026-09-21');
    expect($window->weekStart($monday)->toDateString())->toBe('2026-09-18')
        ->and($window->weekEnd($monday)->toDateString())->toBe('2026-09-22')
        ->and($window->remainingWorkingDaysFrom($monday))->toBe(['2026-09-21', '2026-09-22'])
        ->and($window->remainingWorkingDaysFrom(Carbon::parse('2026-09-23')))->toBe([]);
});

test('explicit day rules override weekend fallback only within effective dates', function (): void {
    CafeteriaDayRule::query()->create(['day_of_week' => 6, 'is_open' => true, 'is_subsidy_day' => true, 'is_active' => true, 'effective_from' => '2026-09-26', 'effective_to' => '2026-09-26']);
    $calendar = app(WorkingDayCalendarService::class);
    expect($calendar->isSubsidyDay(Carbon::parse('2026-09-26')))->toBeTrue()
        ->and($calendar->isCafeteriaOpen(Carbon::parse('2026-10-03')))->toBeFalse();
});

test('a provider special day cannot change another providers calendar', function (): void {
    [, , $provider] = operationalScanFixture();
    $other = CafeteriaProvider::query()->create(['code' => 'OTHER', 'name_en' => 'Other', 'is_active' => true]);
    CafeteriaSpecialDay::query()->create(['name_en' => 'Closed', 'special_date' => '2026-09-21', 'day_type' => 'closed_day', 'is_open' => false, 'is_subsidy_day' => false, 'is_active' => true, 'cafeteria_provider_id' => $provider->id]);
    $calendar = app(WorkingDayCalendarService::class);
    expect($calendar->isCafeteriaOpen(Carbon::parse('2026-09-21'), $provider))->toBeFalse()
        ->and($calendar->isCafeteriaOpen(Carbon::parse('2026-09-21'), $other))->toBeTrue();
});

test('scan defaults and upfront restriction are enforced server side', function (): void {
    [, $card, $provider] = operationalScanFixture();
    $settings = app(CafeteriaSettingsService::class);
    $settings->set('default_usage_mode', 'use_remaining_week');
    $scan = app(CafeteriaQrScanService::class)->process($card, $provider, Carbon::parse('2026-09-21 12:00'), options: ['scan_nonce' => (string) Str::uuid()]);
    expect($scan['allowed'])->toBeTrue()->and($scan['usage_mode'])->toBe('use_remaining_week')->and($scan['subsidy_applied'])->toBe(200.0);
    $settings->set('allow_upfront_weekday_usage', false);
    $denied = app(CafeteriaQrScanService::class)->process($card, $provider, Carbon::parse('2026-09-21 12:01'), options: ['usage_mode' => 'use_remaining_week']);
    expect($denied['denial_reason'])->toBe('upfront_usage_disabled')->and($settings->scanOptions()['default_usage_mode'])->toBe('single_day');
});

test('transaction limits reject before creating transactions', function (): void {
    [, $card, $provider] = operationalScanFixture();
    app(CafeteriaSettingsService::class)->set('max_transaction_amount_per_scan', 39);
    $scan = app(CafeteriaQrScanService::class)->process($card, $provider, Carbon::parse('2026-09-21 12:00'));
    expect($scan['denial_reason'])->toBe('transaction_limit_exceeded')->and(CafeteriaTransaction::query()->count())->toBe(0);
});

test('holiday employee payable does not consume subsidy dates', function (): void {
    [, $card, $provider] = operationalScanFixture();
    PublicHoliday::query()->create(['name_en' => 'Holiday', 'holiday_date' => '2026-09-21', 'is_active' => true]);
    app(CafeteriaSettingsService::class)->set('holiday_scan_mode', 'employee_payable');
    $scan = app(CafeteriaQrScanService::class)->process($card, $provider, Carbon::parse('2026-09-21 12:00'));
    expect($scan['allowed'])->toBeTrue()->and($scan['subsidy_applied'])->toBe(0.0)->and($scan['employee_payable'])->toBe(40.0)->and($scan['consumed_dates'])->toBe([]);
});

test('leave exclusion toggle changes available subsidy without disabling leave scan protection', function (): void {
    [$employee, $card, $provider] = operationalScanFixture();
    EmployeeCafeteriaExclusion::query()->create(['employee_id' => $employee->id, 'exclusion_type' => 'leave', 'starts_on' => '2026-09-22', 'ends_on' => '2026-09-23', 'status' => 'active']);
    $settings = app(CafeteriaSettingsService::class);
    $settings->set('exclude_leave_days_from_subsidy', false);
    $scan = app(CafeteriaQrScanService::class)->process($card, $provider, Carbon::parse('2026-09-21 12:00'), options: ['usage_mode' => 'use_remaining_week']);
    expect($scan['subsidy_applied'])->toBe(200.0);
    $denied = app(CafeteriaQrScanService::class)->process($card, $provider, Carbon::parse('2026-09-22 12:00'));
    expect($denied['denial_reason'])->toBe('employee_on_leave');
});

test('leave exclusion removes future leave days from remaining week subsidy', function (): void {
    [$employee, $card, $provider] = operationalScanFixture();
    EmployeeCafeteriaExclusion::query()->create(['employee_id' => $employee->id, 'exclusion_type' => 'leave', 'starts_on' => '2026-09-22', 'ends_on' => '2026-09-23', 'status' => 'active']);
    $scan = app(CafeteriaQrScanService::class)->process($card, $provider, Carbon::parse('2026-09-21 12:00'), options: ['usage_mode' => 'use_remaining_week']);
    expect($scan['subsidy_applied'])->toBe(120.0)->and($scan['consumed_dates'])->toBe(['2026-09-21', '2026-09-24', '2026-09-25']);
});

test('return to work resumes scans and calendar availability on the same date', function (): void {
    $this->travelTo(Carbon::parse('2026-09-21 12:00'));
    [$employee, $card, $provider] = operationalScanFixture();
    EmployeeCafeteriaExclusion::query()->create(['employee_id' => $employee->id, 'exclusion_type' => 'leave', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'return_to_work_on' => '2026-09-21', 'status' => 'active']);
    $calendar = app(CafeteriaCalendarService::class)->getEmployeeWeekCalendar($employee, now(), $provider);
    expect($calendar[0]['is_available'])->toBeTrue()->and($calendar[0]['is_employee_excluded'])->toBeFalse();
    $scan = app(CafeteriaQrScanService::class)->process($card, $provider, now());
    expect($scan['allowed'])->toBeTrue();
});

test('weekly extra limit applies across providers while employee payable leave scans never consume subsidy', function (): void {
    [$employee, $card, $provider] = operationalScanFixture();
    [, , $otherProvider] = operationalScanFixture();
    EmployeeCafeteriaExclusion::query()->create(['employee_id' => $employee->id, 'exclusion_type' => 'leave', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'active']);
    app(CafeteriaSettingsService::class)->setMany(['leave_scan_mode' => 'employee_payable', 'max_extra_amount_per_week' => 40]);
    $scanner = app(CafeteriaQrScanService::class);
    $first = $scanner->process($card, $provider, Carbon::parse('2026-09-21 12:00'), options: ['usage_mode' => 'use_remaining_week']);
    $second = $scanner->process($card, $provider, Carbon::parse('2026-09-21 13:00'), options: ['usage_mode' => 'use_remaining_week']);
    $third = $scanner->process($card, $otherProvider, Carbon::parse('2026-09-21 14:00'), options: ['usage_mode' => 'use_remaining_week']);
    expect($first['allowed'])->toBeTrue()->and($second['allowed'])->toBeTrue()->and($second['consumed_dates'])->toBe([])
        ->and($third['denial_reason'])->toBe('weekly_extra_limit_exceeded')->and(CafeteriaTransaction::query()->count())->toBe(2);
});

test('excess mode rejects an exhausted single day subsidy', function (): void {
    [, $card, $provider] = operationalScanFixture();
    app(CafeteriaSettingsService::class)->set('excess_amount_mode', 'reject');
    $scanner = app(CafeteriaQrScanService::class);
    $scanner->process($card, $provider, Carbon::parse('2026-09-21 12:00'), options: ['usage_mode' => 'use_remaining_week']);
    $scan = $scanner->process($card, $provider, Carbon::parse('2026-09-22 12:00'));
    expect($scan['denial_reason'])->toBe('excess_amount_rejected')->and(CafeteriaTransaction::query()->count())->toBe(1);
});
