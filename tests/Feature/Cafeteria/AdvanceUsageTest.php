<?php

declare(strict_types=1);

use App\Enums\CafeteriaUsageMode;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Cafeteria\Policy\CafeteriaEntitlementDecision;
use App\Services\Cafeteria\Policy\CafeteriaEntitlementService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyResolver;
use App\Services\Cafeteria\Policy\CafeteriaTransactionPricingService;
use App\Services\Cafeteria\Policy\CafeteriaTransactionService;
use App\Services\Cafeteria\Policy\EntitlementAlreadyConsumed;
use Illuminate\Support\Carbon;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Organization 2');
    $this->s->enroll($this->org, '150.00', $this->s->branch, crossLocation: true);
    [$this->employee, $this->card] = $this->s->employee($this->org);
    $this->scan = fn ($cafeteria, string $at, string $mode = 'single_day') => app(CafeteriaQrScanService::class)
        ->process($this->card, $cafeteria, Carbon::parse($at), options: ['usage_mode' => $mode]);
});

test('26 advance entitlement used at one branch cannot be reused at another location', function (): void {
    $monday = ($this->scan)($this->s->branch, '2026-09-21 12:00', 'use_remaining_week');
    $tuesday = ($this->scan)($this->s->main, '2026-09-22 12:00');

    expect($monday['consumed_dates'])->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'])
        ->and($tuesday['allowed'])->toBeFalse()
        ->and($tuesday['denial_reason'])->toBe('entitlement_already_consumed');
});

test('27 several entitlement dates are consumed by one transaction together', function (): void {
    $result = ($this->scan)($this->s->branch, '2026-09-23 12:00', 'use_remaining_week');

    $claims = CafeteriaTransactionConsumedDay::query()->get();
    expect($result['subsidy_applied'])->toBe(450.0)
        ->and($claims)->toHaveCount(3)
        ->and($claims->pluck('cafeteria_transaction_id')->unique())->toHaveCount(1)
        ->and($claims->pluck('usage_type')->all())->toEqualCanonicalizing(['service_day', 'advance', 'advance'])
        ->and($claims->pluck('organization_id')->unique()->all())->toBe([$this->org->id]);
});

test('28 a failed transaction consumes nothing, not even the dates that were free', function (): void {
    $at = Carbon::parse('2026-09-21 12:00');
    $resolution = app(CafeteriaPolicyResolver::class)->resolve($this->employee, $this->s->branch, $at);
    $decision = app(CafeteriaEntitlementService::class)->evaluate($this->employee, $this->s->branch, $at, $resolution, CafeteriaUsageMode::UseRemainingWeek);
    expect($decision->entitlements)->toHaveCount(5);

    // Another scanner claims Wednesday between this scan's check and its insert.
    ($this->scan)($this->s->main, '2026-09-23 12:00');
    expect(CafeteriaTransactionConsumedDay::query()->count())->toBe(1);

    $pricing = app(CafeteriaTransactionPricingService::class)->price($resolution->policy, 5);
    expect(fn () => app(CafeteriaTransactionService::class)->record(
        $this->employee, $this->card, $at, $at, $resolution, $decision, $pricing, CafeteriaUsageMode::UseRemainingWeek,
    ))->toThrow(EntitlementAlreadyConsumed::class);

    // Only the other scanner's Wednesday remains: no Monday/Tuesday/Thursday/Friday, no transaction.
    expect(CafeteriaTransactionConsumedDay::query()->count())->toBe(1)
        ->and(CafeteriaTransaction::query()->count())->toBe(1);
});

test('29 on friday the remaining week is friday only; next week is never borrowed', function (): void {
    $friday = ($this->scan)($this->s->branch, '2026-09-25 12:00', 'use_remaining_week');

    expect($friday['consumed_dates'])->toBe(['2026-09-25'])
        ->and($friday['subsidy_applied'])->toBe(150.0);

    // Monday of the next week is a fresh entitlement.
    expect(($this->scan)($this->s->main, '2026-09-28 12:00')['allowed'])->toBeTrue();
});

test('past unused days cannot be claimed and advance use respects the policy cap', function (): void {
    CafeteriaServicePolicy::query()->update(['advance_max_days' => 1]);

    $wednesday = ($this->scan)($this->s->branch, '2026-09-23 12:00', 'use_remaining_week');

    // Monday and Tuesday are gone; Wednesday plus one day ahead.
    expect($wednesday['consumed_dates'])->toBe(['2026-09-23', '2026-09-24']);
});

test('advance use needs the policy to allow it', function (): void {
    CafeteriaServicePolicy::query()->update(['allow_advance_usage' => false]);

    expect(($this->scan)($this->s->branch, '2026-09-21 12:00', 'use_remaining_week')['denial_reason'])->toBe('upfront_usage_disabled');
});

test('the extra-scan policy can deduct the next available day instead of blocking', function (): void {
    CafeteriaServicePolicy::query()->update(['extra_scan_policy' => 'deduct_next_available']);

    ($this->scan)($this->s->branch, '2026-09-21 08:00');
    $second = ($this->scan)($this->s->main, '2026-09-21 13:00');

    expect($second['allowed'])->toBeTrue()
        ->and($second['is_extra_scan'])->toBeTrue()
        ->and($second['consumed_dates'])->toBe(['2026-09-22']);
});

test('the extra-scan policy can let the employee pay for another meal', function (): void {
    CafeteriaServicePolicy::query()->update(['extra_scan_policy' => 'employee_paid']);

    ($this->scan)($this->s->branch, '2026-09-21 08:00');
    $second = ($this->scan)($this->s->main, '2026-09-21 13:00');

    expect($second['allowed'])->toBeTrue()
        ->and($second['subsidy_applied'])->toBe(0.0)
        ->and($second['employee_payable'])->toBe(150.0)
        ->and($second['consumed_dates'])->toBe([])
        ->and(CafeteriaTransaction::query()->latest('scanned_at')->first()->transaction_type)->toBe(CafeteriaEntitlementDecision::MODE_EMPLOYEE_PAID);
});
