<?php

declare(strict_types=1);

use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Transport\Application\Services\Boarding\BoardingDecision;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Application\Services\Settlement\TransportInvoiceService;
use Modules\Transport\Application\Services\Settlement\TransportSettlementService;
use Modules\Transport\Application\Services\TransportReportService;
use Modules\Transport\Application\Services\TransportSettings;
use Modules\Transport\Application\Services\TransportTripService;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportException;
use Modules\Transport\Domain\Models\TransportTransaction;
use Modules\Transport\Domain\Models\TransportTrip;
use Tests\Support\TransportScenario;

/*
 * The ten business scenarios of the transport brief (A–J), each run end to
 * end through the boarding engine and read back from the evidence tables.
 * F and G are exercised in depth in TransportSettlementTest; they appear
 * here in their stated form so the list is complete in one place.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 07:10'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->manager = $this->s->portalUser($this->provider, 'manager');
    $this->routeA = $this->s->route($this->provider, ['route_code' => 'ROUTE-A']);
    $this->engine = app(TransportBoardingService::class);
    $this->trips = app(TransportTripService::class);
    $this->settlements = app(TransportSettlementService::class);

    $this->board = fn (IdCard $card, TransportTrip $trip, ?string $key = null): BoardingDecision => $this->engine->board(new BoardingRequest(
        providerId: $this->provider->id, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
        credential: $this->s->qr($card), tripId: $trip->id, idempotencyKey: $key ?? (string) Str::uuid(), providerUser: $this->manager,
    ));
    $this->setting = function (string $key, string $value, string $type = 'string'): void {
        SystemSetting::query()->updateOrCreate(['group' => 'transport', 'key' => $key], [
            'value' => $value, 'type' => $type, 'label_en' => $key, 'label_am' => $key, 'default_value' => '0',
            'is_public' => false, 'is_encrypted' => false, 'is_system' => false, 'is_required' => true,
        ]);
        app(TransportSettings::class)->forget();
    };
    $this->basis = fn () => $this->settlements->basis($this->provider->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
});

it('A: an employee assigned Route A boards Bus 1 in the morning and Bus 3 in the evening', function (): void {
    [$employee, $card] = $this->s->employee();
    $this->s->pass($employee, $this->provider, $this->routeA);
    $bus1 = $this->s->vehicle($this->provider, ['vehicle_code' => 'BUS-1']);
    $bus3 = $this->s->vehicle($this->provider, ['vehicle_code' => 'BUS-3']);
    $morning = $this->s->trip($this->routeA, ['direction' => 'inbound', 'transport_vehicle_id' => $bus1->id]);
    $evening = $this->s->trip($this->routeA, ['direction' => 'outbound', 'departure_time' => '17:30', 'transport_vehicle_id' => $bus3->id]);

    expect(($this->board)($card, $morning)->accepted)->toBeTrue();
    $this->travelTo(Carbon::parse('2026-10-14 17:35'));
    expect(($this->board)($card, $evening)->accepted)->toBeTrue();
    $this->trips->complete($morning, $this->manager);
    $this->trips->complete($evening, $this->manager);

    $rows = TransportTransaction::query()->verified()->orderBy('scanned_at')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->transport_route_id)->toBe($this->routeA->id)
        ->and($rows[0]->direction->value)->toBe('inbound')->and($rows[0]->transport_vehicle_id)->toBe($bus1->id)
        ->and($rows[1]->direction->value)->toBe('outbound')->and($rows[1]->transport_vehicle_id)->toBe($bus3->id);
    $basis = ($this->basis)();
    expect($basis['verified_boardings'])->toBe(2)->and($basis['employee_days'])->toBe(1)->and($basis['unique_riders'])->toBe(1);
});

it('B: an eligible, assigned employee who never rides yields no verified boarding, and the report tells eligible, assigned and actual apart', function (): void {
    [$employee] = $this->s->employee();
    $this->s->grant('woreda_a1');
    $this->s->pass($employee, $this->provider, $this->routeA);
    $this->trips->complete($this->s->trip($this->routeA), $this->manager);

    $reports = app(TransportReportService::class);
    $row = collect($reports->routeUtilization($reports->normaliseFilters(['date_from' => '2026-10-14', 'date_to' => '2026-10-14']), $this->provider->id))->firstWhere('route_id', $this->routeA->id);
    expect($row['assigned_employees'])->toBe(1)->and($row['unique_riders'])->toBe(0)->and($row['boardings'])->toBe(0)
        ->and(($this->basis)()['verified_boardings'])->toBe(0)
        ->and(($this->basis)()['completed_trips'])->toBe(1);
});

it('C: a second scan on the same trip is refused as a duplicate, and a resent request is answered, not recorded', function (): void {
    [$employee, $card] = $this->s->employee();
    $this->s->pass($employee, $this->provider, $this->routeA);
    $trip = $this->s->trip($this->routeA);
    $key = (string) Str::uuid();

    $first = ($this->board)($card, $trip, $key);
    $replay = ($this->board)($card, $trip, $key);
    $second = ($this->board)($card, $trip);

    expect($first->accepted)->toBeTrue()
        ->and($replay->replayed)->toBeTrue()->and($replay->transaction?->id)->toBe($first->transaction?->id)
        ->and($second->accepted)->toBeFalse()->and($second->resultCode)->toBe('already_boarded')
        ->and(TransportTransaction::query()->verified()->where('transport_trip_id', $trip->id)->count())->toBe(1)
        ->and(TransportException::query()->where('type', 'duplicate_boarding_attempt')->count())->toBe(1);
});

it('D: an unauthorized route follows the configured policy and is never a silent billable acceptance', function (): void {
    [$employee, $card] = $this->s->employee();
    $this->s->pass($employee, $this->provider, $this->routeA);
    $routeB = $this->s->route($this->provider, ['route_code' => 'ROUTE-B']);
    $tripB = $this->s->trip($routeB);

    $denied = ($this->board)($card, $tripB);
    expect($denied->accepted)->toBeFalse()->and($denied->resultCode)->toBe('route_not_assigned')
        ->and(TransportException::query()->where('type', 'wrong_route')->count())->toBe(1)
        ->and(TransportTransaction::query()->verified()->count())->toBe(0);

    ($this->setting)('alternative_route_policy', 'allow_same_provider');
    $allowed = ($this->board)($card, $tripB);
    expect($allowed->accepted)->toBeTrue()
        ->and($allowed->transaction?->eligibility_snapshot['alternative_route'] ?? null)->toBeTrue();
});

it('E: fifty-two scans against a capacity of fifty are capped or flagged as configured, with the evidence kept', function (): void {
    $riders = collect(range(1, 52))->map(function (int $i): IdCard {
        [$employee, $card] = $this->s->employee('woreda_a1', "Rider {$i}");
        $this->s->pass($employee, $this->provider, $this->routeA);

        return $card;
    });
    $bus = $this->s->vehicle($this->provider, ['capacity' => 50]);

    $blocked = $this->s->trip($this->routeA, ['transport_vehicle_id' => $bus->id]);
    $results = $riders->map(fn (IdCard $card) => ($this->board)($card, $blocked)->resultCode);
    expect($results->filter(fn ($r) => $r === 'transport_scan_accepted')->count())->toBe(50)
        ->and($results->filter(fn ($r) => $r === 'trip_full')->count())->toBe(2)
        ->and($blocked->fresh()->boarded_count)->toBe(50)
        ->and(TransportTransaction::query()->where('transport_trip_id', $blocked->id)->where('status', 'rejected')->count())->toBe(2)
        ->and(TransportException::query()->where('transport_trip_id', $blocked->id)->where('type', 'overcapacity')->count())->toBe(2);

    ($this->setting)('capacity_enforcement', 'warn');
    $this->travelTo(Carbon::parse('2026-10-14 17:30'));
    $warned = $this->s->trip($this->routeA, ['direction' => 'outbound', 'transport_vehicle_id' => $bus->id]);
    $results = $riders->map(fn (IdCard $card) => ($this->board)($card, $warned)->accepted);
    expect($results->filter()->count())->toBe(52)
        ->and($warned->fresh()->boarded_count)->toBe(52)
        ->and(TransportException::query()->where('transport_trip_id', $warned->id)->where('type', 'overcapacity')->count())->toBe(2);
});

it('F: a claim of fifty against thirty-seven verified boardings shows the discrepancy and keeps both figures', function (): void {
    $riders = collect(range(1, 37))->map(function (int $i): IdCard {
        [$employee, $card] = $this->s->employee('woreda_a1', "Rider {$i}");
        $this->s->pass($employee, $this->provider, $this->routeA);

        return $card;
    });
    $trip = $this->s->trip($this->routeA, ['transport_vehicle_id' => $this->s->vehicle($this->provider, ['capacity' => 60])->id]);
    $riders->each(fn (IdCard $card) => ($this->board)($card, $trip));
    $this->trips->complete($trip, $this->manager);
    $contract = $this->s->contract($this->provider);
    $invoice = app(TransportInvoiceService::class)->draft($this->provider->id, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_boardings' => 50, 'claimed_amount' => '625.00', 'invoice_number' => null], $this->manager);
    $finance = $this->s->staff(['transport-settlements.manage']);
    $this->travelTo(Carbon::parse('2026-11-02 09:00'));

    $settlement = $this->settlements->compute($this->provider->id, $contract, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $finance, $invoice);
    $reconciliation = $this->settlements->reconciliation($settlement);

    expect($reconciliation['claimed_boardings'])->toBe(50)->and($reconciliation['verified_boardings'])->toBe(37)->and($reconciliation['boardings_difference'])->toBe(13)
        ->and($reconciliation['claimed_amount'])->toBe('625.00')->and($reconciliation['calculated_amount'])->toBe('462.50')
        ->and($settlement->fresh()->claimed_amount)->not->toBeNull()->and((string) $settlement->fresh()->calculated_amount)->toBe('462.50');
});

it('G: a scheduled trip nobody boards is still completed, and what it is worth depends on the contract model', function (): void {
    $trip = $this->s->trip($this->routeA);
    $this->trips->complete($trip, $this->manager);
    $other = $this->s->provider('Per Boarding Lines');
    $otherTrip = $this->s->trip($this->s->route($other));
    $this->trips->complete($otherTrip, $this->s->portalUser($other, 'manager'));
    $finance = $this->s->staff(['transport-settlements.manage']);
    $this->travelTo(Carbon::parse('2026-11-02 09:00'));
    [$from, $to] = [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')];

    $perTrip = $this->settlements->compute($this->provider->id, $this->s->contract($this->provider, 'per_completed_trip', ['rate_per_trip' => '800.00']), $from, $to, $finance);
    $perBoarding = $this->settlements->compute($other->id, $this->s->contract($other, 'per_verified_boarding', ['rate_per_boarding' => '12.50']), $from, $to, $finance);

    expect($trip->fresh()->status->value)->toBe('completed')
        ->and($perTrip->completed_trips)->toBe(1)->and($perTrip->verified_boardings)->toBe(0)->and((string) $perTrip->calculated_amount)->toBe('800.00')
        ->and($perBoarding->completed_trips)->toBe(1)->and((string) $perBoarding->calculated_amount)->toBe('0.00');
});

it('H: a vehicle replaced mid-operation leaves every boarding traceable to the bus actually used', function (): void {
    $bus1 = $this->s->vehicle($this->provider, ['vehicle_code' => 'BUS-1']);
    $bus2 = $this->s->vehicle($this->provider, ['vehicle_code' => 'BUS-2']);
    $trip = $this->s->trip($this->routeA, ['transport_vehicle_id' => $bus1->id]);
    [$e1, $c1] = $this->s->employee('woreda_a1', 'Before');
    [$e2, $c2] = $this->s->employee('woreda_a1', 'After');
    $this->s->pass($e1, $this->provider, $this->routeA);
    $this->s->pass($e2, $this->provider, $this->routeA);

    ($this->board)($c1, $trip);
    $this->trips->replaceVehicle($trip, $bus2, null, 'Breakdown at Megenagna', $this->manager);
    ($this->board)($c2, $trip->fresh());

    expect(TransportTransaction::query()->where('employee_id', $e1->id)->value('transport_vehicle_id'))->toBe($bus1->id)
        ->and(TransportTransaction::query()->where('employee_id', $e2->id)->value('transport_vehicle_id'))->toBe($bus2->id)
        ->and($trip->fresh()->transport_vehicle_id)->toBe($bus2->id)
        ->and($trip->vehicleChanges()->count())->toBe(1)
        ->and(TransportException::query()->where('type', 'vehicle_substituted')->count())->toBe(1);
});

it('I: a transfer keeps the identity and re-evaluates the route assignment', function (): void {
    $route = $this->s->route($this->provider, ['assigned_scope_type' => 'subtree', 'assigned_organization_id' => $this->s->org['subcity_a']->id]);
    [$employee, $card] = $this->s->employee('woreda_a1');
    $pass = $this->s->pass($employee, $this->provider, $route);
    expect(($this->board)($card, $this->s->trip($route))->accepted)->toBeTrue();

    $old = EmployeeAssignment::query()->find($employee->current_assignment_id);
    $old->update(['is_current' => false, 'assignment_status' => 'closed', 'effective_to' => today()->toDateString()]);
    $new = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $this->s->org['subcity_b']->id, 'assignment_status' => 'active', 'effective_from' => today()->toDateString(), 'is_current' => true]);
    $employee->forceFill(['current_assignment_id' => $new->id])->save();

    expect($pass->fresh()->status)->toBe('suspended')
        ->and($card->fresh()->public_card_uuid)->toBe($card->public_card_uuid)
        ->and(($this->board)($card, $this->s->trip($route, ['direction' => 'outbound']))->resultCode)->toBe('route_not_allowed_for_employee')
        ->and(TransportTransaction::query()->verified()->where('employee_id', $employee->id)->count())->toBe(1);
});

it('J: a revoked card is refused from then on while its history stands', function (): void {
    [$employee, $card] = $this->s->employee();
    $this->s->pass($employee, $this->provider, $this->routeA);
    expect(($this->board)($card, $this->s->trip($this->routeA))->accepted)->toBeTrue();

    $card->update(['status' => 'revoked', 'revoked_at' => now(), 'is_current' => false]);
    $later = ($this->board)($card, $this->s->trip($this->routeA, ['direction' => 'outbound']));

    expect($later->accepted)->toBeFalse()->and($later->resultCode)->toBe('id_card_revoked')
        ->and(TransportTransaction::query()->verified()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(TransportTransaction::query()->where('status', 'rejected')->where('employee_id', $employee->id)->count())->toBe(1);
});
