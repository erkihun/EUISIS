<?php

declare(strict_types=1);

use App\Enums\CafeteriaPolicyStatus;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaCalendarService;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyResolver;
use App\Services\Cafeteria\Policy\CafeteriaPolicyWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-15 09:00'));
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Organization 2');
    $this->v1 = $this->s->enroll($this->org, '120.00', $this->s->main);
    $this->workflow = app(CafeteriaPolicyWorkflowService::class);
    $this->maker = User::factory()->create();
    $this->checker = User::factory()->create();
    [$this->employee, $this->card] = $this->s->employee($this->org);
    $this->scan = fn (string $at) => app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, Carbon::parse($at));

    // v2: 150 ETB from 1 October, drafted as a new version, reviewed, approved by someone else.
    $this->approveVersion = function (string $subsidy = '150.00', string $from = '2026-10-01'): CafeteriaServicePolicy {
        $draft = $this->workflow->createNewVersion($this->v1, $this->maker);
        $this->workflow->updateDraft($draft, [...$draft->only(CafeteriaServicePolicy::TERMS), 'extra_scan_policy' => $draft->extra_scan_policy,
            'daily_subsidy_amount' => $subsidy, 'provider_price' => $subsidy, 'effective_from' => $from], $this->maker);
        $this->workflow->submit($draft, $this->maker);

        return $this->workflow->approve($draft->fresh(), $this->checker);
    };
});

test('30-31 the old policy applies before the new version, the new one from its date', function (): void {
    $v2 = ($this->approveVersion)();

    expect($this->v1->fresh()->effective_to->toDateString())->toBe('2026-09-30')
        ->and($v2->version_no)->toBe(2)
        ->and(($this->scan)('2026-09-21 12:00')['subsidy_applied'])->toBe(120.0)
        ->and(($this->scan)('2026-10-05 12:00')['subsidy_applied'])->toBe(150.0);
});

test('32 a recorded transaction never changes when the policy changes', function (): void {
    ($this->scan)('2026-09-21 12:00');
    ($this->approveVersion)('200.00', '2026-09-22');

    $transaction = CafeteriaTransaction::query()->sole();
    expect((string) $transaction->subsidy_amount_applied)->toBe('120.00')
        ->and($transaction->cafeteria_policy_version)->toBe(1)
        ->and($transaction->policy_snapshot['daily_subsidy_amount'])->toBe('120.00')
        ->and($transaction->cafeteria_service_policy_id)->toBe($this->v1->id);
});

test('33 two approved policies for one scope may not overlap', function (): void {
    $rival = $this->workflow->createDraft([
        ...$this->v1->fresh()->only(CafeteriaServicePolicy::TERMS), 'extra_scan_policy' => 'block',
        'cafeteria_service_assignment_id' => $this->v1->cafeteria_service_assignment_id,
        'daily_subsidy_amount' => '99.00', 'provider_price' => '99.00', 'effective_from' => '2026-06-01',
    ], $this->maker);
    $this->workflow->submit($rival, $this->maker);

    expect(fn () => $this->workflow->approve($rival->fresh(), $this->checker))->toThrow(ValidationException::class)
        ->and($rival->fresh()->status)->toBe(CafeteriaPolicyStatus::UnderReview);
});

test('34 an approved future version does not apply early', function (): void {
    ($this->approveVersion)();

    expect(($this->scan)('2026-09-28 12:00')['subsidy_applied'])->toBe(120.0)
        ->and(app(CafeteriaPolicyResolver::class)->resolve($this->employee, $this->s->main, Carbon::parse('2026-09-30'))->policy->id)->toBe($this->v1->id);
});

test('the drafter cannot approve their own policy and approved terms cannot be edited', function (): void {
    $draft = $this->workflow->createNewVersion($this->v1, $this->maker);
    $this->workflow->submit($draft, $this->maker);

    expect(fn () => $this->workflow->approve($draft->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $this->workflow->updateDraft($this->v1->fresh(), ['daily_subsidy_amount' => '1.00', 'provider_price' => '1.00', 'monday_enabled' => true, 'effective_from' => '2026-01-01'], $this->maker))
        ->toThrow(ValidationException::class);
});

test('cancelling a future version restores the version it would have replaced', function (): void {
    $v2 = ($this->approveVersion)();
    expect($this->v1->fresh()->effective_to->toDateString())->toBe('2026-09-30');

    $this->workflow->cancel($v2, $this->checker, 'Budget not released');

    expect($this->v1->fresh()->effective_to)->toBeNull()
        ->and($v2->fresh()->status)->toBe(CafeteriaPolicyStatus::Cancelled)
        ->and(($this->scan)('2026-10-05 12:00')['subsidy_applied'])->toBe(120.0);
});

test('a version becomes active on its date and supersedes its predecessor', function (): void {
    $v2 = ($this->approveVersion)();
    $this->travelTo(Carbon::parse('2026-10-01 00:05'));

    expect($this->workflow->syncStatuses())->toBeGreaterThan(0)
        ->and($v2->fresh()->status)->toBe(CafeteriaPolicyStatus::Active)
        ->and($this->v1->fresh()->status)->toBe(CafeteriaPolicyStatus::Superseded);
});

test('the provider price must equal the subsidy plus the employee contribution', function (): void {
    expect(fn () => $this->workflow->createDraft([
        ...$this->v1->fresh()->only(CafeteriaServicePolicy::TERMS), 'extra_scan_policy' => 'block',
        'cafeteria_service_assignment_id' => $this->v1->cafeteria_service_assignment_id,
        'daily_subsidy_amount' => '120.00', 'employee_contribution_amount' => '10.00', 'provider_price' => '120.00',
        'effective_from' => '2027-01-01',
    ], $this->maker))->toThrow(ValidationException::class);
});

test('employee contribution is charged to the employee on top of the subsidy', function (): void {
    CafeteriaServicePolicy::query()->update(['employee_contribution_amount' => '30.00', 'provider_price' => '150.00']);

    $result = ($this->scan)('2026-09-21 12:00');

    $transaction = CafeteriaTransaction::query()->sole();
    expect($result['subsidy_applied'])->toBe(120.0)
        ->and($result['employee_payable'])->toBe(30.0)
        ->and((string) $transaction->total_amount_applied)->toBe('150.00')
        ->and((string) $transaction->provider_price_applied)->toBe('150.00');
});

test('the scan calendar follows the employee organization policy, and shows nothing available without one', function (): void {
    $calendar = app(CafeteriaCalendarService::class);

    // Mon–Fri policy: Saturday is not an entitlement day even if the cafeteria opens on Saturdays.
    CafeteriaServicePolicy::query()->update(['saturday_enabled' => false]);
    $week = collect($calendar->getEmployeeWeekCalendar($this->employee, Carbon::parse('2026-09-21'), $this->s->main))->keyBy('date');
    expect($week['2026-09-21']['is_subsidy_day'])->toBeTrue()
        ->and($week['2026-09-26']['is_subsidy_day'])->toBeFalse();

    // An organization without a policy: every day unavailable, labelled as such.
    $orphan = $this->s->organization('No Policy Org');
    [$employee] = $this->s->employee($orphan);
    $days = $calendar->getEmployeeWeekCalendar($employee, Carbon::parse('2026-09-21'), $this->s->main);
    expect(collect($days)->where('is_available', true))->toHaveCount(0)
        ->and($days[0]['reason_code'])->toBe('no_policy');
});

test('35-37 a transfer changes the policy from its effective date, never before', function (): void {
    $org1 = $this->s->organization('Organization 1');
    $this->s->enroll($org1, '100.00', $this->s->main);

    // The employee moves from Org 1 to Org 2 on 1 October.
    [$employee, $card] = $this->s->employee($org1, '2026-01-01', '2026-09-30');
    $this->s->assignEmployee($employee, $this->org, '2026-10-01');
    $scan = fn (string $at) => app(CafeteriaQrScanService::class)->process($card, $this->s->main, Carbon::parse($at));

    $before = $scan('2026-09-28 12:00');
    $after = $scan('2026-10-05 12:00');

    expect($before['employee_organization_id'])->toBe($org1->id)
        ->and($before['subsidy_applied'])->toBe(100.0)
        ->and($after['employee_organization_id'])->toBe($this->org->id)
        ->and($after['subsidy_applied'])->toBe(120.0)
        // The September meal stays Org 1's liability.
        ->and(CafeteriaTransaction::query()->whereDate('transaction_date', '2026-09-28')->value('employee_organization_id'))->toBe($org1->id);
});
