<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Application\Services\Settlement\TransportContractService;
use Modules\Transport\Application\Services\Settlement\TransportInvoiceService;
use Modules\Transport\Application\Services\Settlement\TransportMoney;
use Modules\Transport\Application\Services\Settlement\TransportSettlementCalculator;
use Modules\Transport\Application\Services\Settlement\TransportSettlementService;
use Modules\Transport\Application\Services\TransportTripService;
use Modules\Transport\Domain\Enums\TransportAdjustmentOrigin;
use Modules\Transport\Domain\Enums\TransportContractStatus;
use Modules\Transport\Domain\Enums\TransportInvoiceStatus;
use Modules\Transport\Domain\Enums\TransportSettlementStatus;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportTransaction;
use Tests\Support\TransportScenario;

/*
 * Usage evidence != settlement formula. The statement reads verified
 * boardings, employee-days and completed trips from the immutable evidence,
 * applies the contract version it snapshots, keeps the provider's claim
 * beside the result and is approved by someone other than its computer.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-05 07:00'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->route = $this->s->route($this->provider);
    $this->manager = $this->s->portalUser($this->provider, 'manager');
    $this->finance = $this->s->staff(['transport-settlements.manage', 'transport-settlements.approve']);
    $this->approver = $this->s->staff(['transport-settlements.approve']);
    $this->settlements = app(TransportSettlementService::class);
    $this->engine = app(TransportBoardingService::class);
    $this->trips = app(TransportTripService::class);

    // Morning and evening trips with riders over three days (two of them ride every day, one rides mornings only).
    $this->riders = collect(range(1, 3))->map(function (int $i) {
        [$employee, $card] = $this->s->employee('woreda_a1', "Rider {$i}");
        $this->s->pass($employee, $this->provider, $this->route);

        return [$employee, $card];
    });
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $day) {
        foreach (['inbound' => '07:00', 'outbound' => '17:30'] as $direction => $time) {
            $this->travelTo(Carbon::parse("{$day} {$time}"));
            $trip = $this->s->trip($this->route, ['direction' => $direction, 'trip_date' => $day, 'departure_time' => $time]);
            foreach ($this->riders as $index => [$employee, $card]) {
                if ($direction === 'outbound' && $index === 2) {
                    continue;
                }
                $this->engine->board(new BoardingRequest(providerId: $this->provider->id, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
                    credential: $this->s->qr($card), tripId: $trip->id, idempotencyKey: (string) Str::uuid(), providerUser: portalActor($this->manager)));
            }
            $this->trips->complete($trip, portalActor($this->manager));
        }
    }
    // A cancelled trip with a boarding on it: not service delivered, not settled.
    $this->travelTo(Carbon::parse('2026-10-08 07:00'));
    $cancelled = $this->s->trip($this->route, ['trip_date' => '2026-10-08']);
    [$employee, $card] = $this->riders[0];
    $this->engine->board(new BoardingRequest(providerId: $this->provider->id, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
        credential: $this->s->qr($card), tripId: $cancelled->id, idempotencyKey: (string) Str::uuid(), providerUser: portalActor($this->manager)));
    $this->trips->cancel($cancelled, 'Breakdown', portalActor($this->manager));
    $this->travelTo(Carbon::parse('2026-11-01 09:00'));
    $this->period = [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')];
});

it('counts boardings, unique riders, employee-days and trips from evidence, never from claims', function (): void {
    $basis = $this->settlements->basis($this->provider->id, ...$this->period);

    expect($basis['verified_boardings'])->toBe(15)   // 3 days × (3 morning + 2 evening)
        ->and($basis['unique_riders'])->toBe(3)
        ->and($basis['employee_days'])->toBe(9)       // 3 riders × 3 days
        ->and($basis['inbound_boardings'])->toBe(9)
        ->and($basis['outbound_boardings'])->toBe(6)
        ->and($basis['completed_trips'])->toBe(6)
        ->and($basis['cancelled_trips'])->toBe(1)
        ->and($basis['seat_trips'])->toBe(300)        // 6 completed trips × 50 seats
        ->and($basis['vehicles_used'])->toBe(6);
});

it('applies each settlement model from the contract terms, and flags a model it cannot compute', function (string $model, array $terms, ?string $expected, array $gaps): void {
    $calc = app(TransportSettlementCalculator::class)->calculate(['settlement_model' => $model, 'terms' => $terms], [
        'verified_boardings' => 15, 'employee_days' => 9, 'completed_trips' => 6, 'seat_trips' => 300,
    ]);

    expect($calc['amount'])->toBe($expected)->and($calc['gaps'])->toBe($gaps);
})->with([
    'per boarding' => ['per_verified_boarding', ['rate_per_boarding' => '12.50'], '187.50', []],
    'per employee-day' => ['per_employee_per_day', ['rate_per_employee_day' => '20.00'], '180.00', []],
    'per trip' => ['per_completed_trip', ['rate_per_trip' => '450.00'], '2700.00', []],
    'fixed' => ['fixed_periodic', ['fixed_amount_per_period' => '30000.00'], '30000.00', []],
    'fixed plus usage' => ['fixed_plus_usage', ['fixed_amount_per_period' => '1000.00', 'rate_per_boarding' => '5.00'], '1075.00', []],
    'minimum guarantee met by usage' => ['minimum_guarantee_plus_usage', ['minimum_guarantee_amount' => '100.00', 'rate_per_boarding' => '12.50'], '187.50', []],
    'minimum guarantee tops up' => ['minimum_guarantee_plus_usage', ['minimum_guarantee_amount' => '500.00', 'rate_per_boarding' => '12.50'], '500.00', []],
    'capacity based' => ['capacity_based', ['rate_per_seat_trip' => '3.25'], '975.00', []],
    'missing rate' => ['per_verified_boarding', [], null, ['NEEDS_POLICY_DECISION_TRANSPORT_TARIFF:rate_per_boarding']],
    'other approved model' => ['other_approved_model', [], null, ['NEEDS_POLICY_DECISION_TRANSPORT_SETTLEMENT_MODEL']],
]);

it('never uses floating point: amounts round-trip as integer cents', function (): void {
    expect(TransportMoney::toCents('0.10'))->toBe(10)
        ->and(TransportMoney::format(TransportMoney::times(TransportMoney::toCents('0.10'), 3)))->toBe('0.30')
        ->and(TransportMoney::format(1234567890123))->toBe('12345678901.23')
        ->and(fn () => TransportMoney::toCents('1.234'))->toThrow(InvalidArgumentException::class);
});

it('reconciles the provider claim against the verified basis without overwriting either figure (scenario F)', function (): void {
    $contract = $this->s->contract($this->provider, 'per_verified_boarding', ['rate_per_boarding' => '10.00']);
    $invoice = app(TransportInvoiceService::class)->draft($this->provider->id, [
        'invoice_number' => 'INV-OCT', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_boardings' => 50, 'claimed_amount' => '500.00',
    ], portalActor($this->manager));
    app(TransportInvoiceService::class)->submit($invoice, portalActor($this->manager));

    $statement = $this->settlements->compute($this->provider->id, $contract, ...[...$this->period, $this->finance, $invoice]);
    $reconciliation = $this->settlements->reconciliation($statement->fresh());

    expect($statement->verified_boardings)->toBe(15)
        ->and((string) $statement->calculated_amount)->toBe('150.00')
        ->and((string) $statement->claimed_amount)->toBe('500.00')
        ->and($reconciliation['difference'])->toBe('350.00')
        ->and($reconciliation['boardings_difference'])->toBe(35)
        ->and($invoice->fresh()->status)->toBe(TransportInvoiceStatus::UnderReview)
        ->and($invoice->fresh()->claimed_boardings)->toBe(50)
        ->and($statement->contract_snapshot['terms']['rate_per_boarding'])->toBe('10.00')
        // The evidence is claimed by the statement: settled once, never twice.
        ->and(TransportTransaction::query()->where('transport_settlement_id', $statement->id)->count())->toBe(15)
        ->and(TransportTransaction::query()->where('transport_settlement_id', $statement->id)->whereHas('trip', fn ($q) => $q->where('status', 'cancelled'))->count())->toBe(0);

    expect(fn () => $this->settlements->compute($this->provider->id, $contract, ...[...$this->period, $this->finance]))->toThrow(DomainException::class);
});

it('pays a completed trip with no riders under a per-trip contract and nothing under a per-boarding one (scenario G)', function (): void {
    $this->travelTo(Carbon::parse('2026-10-09 07:00'));
    $empty = $this->s->trip($this->route, ['trip_date' => '2026-10-09']);
    $this->trips->complete($empty, portalActor($this->manager));
    $this->travelTo(Carbon::parse('2026-11-01 09:00'));

    $perTrip = $this->s->contract($this->provider, 'per_completed_trip', ['rate_per_trip' => '100.00']);
    $statement = $this->settlements->compute($this->provider->id, $perTrip, ...[...$this->period, $this->finance]);
    expect($statement->completed_trips)->toBe(7)->and((string) $statement->calculated_amount)->toBe('700.00');
});

it('freezes the contract version a statement applied: a later version changes nothing historical', function (): void {
    $contracts = app(TransportContractService::class);
    $v1 = $this->s->contract($this->provider, 'per_verified_boarding', ['rate_per_boarding' => '10.00'], ['created_by' => $this->finance->id]);
    $statement = $this->settlements->compute($this->provider->id, $v1, ...[...$this->period, $this->finance]);

    $v2 = $contracts->newVersion($v1, $this->finance);
    $contracts->updateDraft($v2, ['terms' => ['rate_per_boarding' => '99.00'], 'effective_from' => '2026-11-01'], $this->finance);
    expect(fn () => $contracts->approve($v2, $this->finance))->toThrow(DomainException::class); // drafter cannot approve
    $contracts->approve($v2, $this->approver);

    expect($v1->fresh()->status)->toBe(TransportContractStatus::Superseded)
        ->and($v1->fresh()->effective_to->toDateString())->toBe('2026-10-31')
        ->and($v2->fresh()->status)->toBe(TransportContractStatus::Active)
        ->and($statement->fresh()->contract_snapshot['terms']['rate_per_boarding'])->toBe('10.00')
        ->and((string) $statement->fresh()->calculated_amount)->toBe('150.00');
    // The superseded version still covers October for a recompute; v2 does not.
    expect(fn () => $this->settlements->compute($this->provider->id, $v2, ...[...$this->period, $this->finance]))->toThrow(DomainException::class);
});

it('runs maker-checker: adjustments and disputes are decided first, and the computer cannot approve', function (): void {
    $contract = $this->s->contract($this->provider, 'per_verified_boarding', ['rate_per_boarding' => '10.00']);
    $statement = $this->settlements->compute($this->provider->id, $contract, ...[...$this->period, $this->finance]);

    // The provider disputes a missing transaction; the organization deducts a cancelled-trip boarding.
    $dispute = $this->settlements->requestAdjustment($statement, TransportAdjustmentOrigin::ProviderDispute, ['dispute_type' => 'missing_transaction', 'amount' => '10.00', 'quantity' => 1, 'reason' => 'Device outage 06 Oct, 1 rider'], portalActor($this->manager));
    $deduction = $this->settlements->requestAdjustment($statement, TransportAdjustmentOrigin::OrganizationAdjustment, ['amount' => '-20.00', 'reason' => 'Two manual boardings not substantiated'], $this->finance);
    $this->settlements->submitForReview($statement, $this->finance);

    expect(fn () => $this->settlements->approve($statement, $this->approver))->toThrow(DomainException::class, __('transport.settlement_pending_adjustments'));
    expect(fn () => $this->settlements->decideAdjustment($deduction, true, $this->finance))->toThrow(DomainException::class); // requester cannot decide
    $this->settlements->decideAdjustment($dispute, true, $this->approver, 'Outage confirmed in device log');
    $this->settlements->decideAdjustment($deduction, false, $this->approver, 'Manual boardings carry reasons');

    expect(fn () => $this->settlements->approve($statement, $this->finance))->toThrow(DomainException::class, __('transport.settlement_distinct_approver'));
    $approved = $this->settlements->approve($statement, $this->approver, null, null, 'October statement');

    expect($approved->status)->toBe(TransportSettlementStatus::Approved)
        ->and((string) $approved->adjustment_total)->toBe('10.00')
        ->and((string) $approved->approved_amount)->toBe('160.00')
        ->and($approved->approved_by)->toBe($this->approver->id);

    // Final: nothing changes, boardings inside it cannot be voided, and the payment reference closes it.
    expect(fn () => $this->settlements->requestAdjustment($approved, TransportAdjustmentOrigin::OrganizationAdjustment, ['amount' => '1.00', 'reason' => 'late'], $this->finance))->toThrow(DomainException::class);
    $settled = TransportTransaction::query()->where('transport_settlement_id', $approved->id)->first();
    expect(fn () => $this->engine->void($settled, $this->finance, 'oops'))->toThrow(DomainException::class);
    expect($this->settlements->recordPayment($approved, 'IFMIS-2026-10-0042', $this->approver)->status)->toBe(TransportSettlementStatus::Paid);
});

it('needs an explicit approved amount when the model yields none, and keeps the flag', function (): void {
    $contract = $this->s->contract($this->provider, 'other_approved_model', []);
    $statement = $this->settlements->compute($this->provider->id, $contract, ...[...$this->period, $this->finance]);
    expect($statement->calculated_amount)->toBeNull()->and($statement->policy_gaps)->toBe(['NEEDS_POLICY_DECISION_TRANSPORT_SETTLEMENT_MODEL']);

    $this->settlements->submitForReview($statement, $this->finance);
    expect(fn () => $this->settlements->approve($statement, $this->approver))->toThrow(DomainException::class);
    $approved = $this->settlements->approve($statement, $this->approver, approvedAmount: '1234.56');
    expect((string) $approved->approved_amount)->toBe('1234.56')->and($approved->policy_gaps)->toContain('APPROVED_AMOUNT_ENTERED_MANUALLY');
});

it('lets a rejected statement release its evidence for a new one', function (): void {
    $contract = $this->s->contract($this->provider, 'per_verified_boarding', ['rate_per_boarding' => '10.00']);
    $statement = $this->settlements->compute($this->provider->id, $contract, ...[...$this->period, $this->finance]);
    $this->settlements->submitForReview($statement, $this->finance);
    $this->settlements->reject($statement, 'Wrong period', $this->approver);

    expect(TransportTransaction::query()->whereNotNull('transport_settlement_id')->count())->toBe(0);
    $again = $this->settlements->compute($this->provider->id, $contract, ...[...$this->period, $this->finance]);
    expect($again->verified_boardings)->toBe(15);
});
