<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Application\Services\Settlement\TransportInvoiceService;
use Modules\Transport\Application\Services\Settlement\TransportMoney;
use Modules\Transport\Application\Services\Settlement\TransportSettlementService;
use Modules\Transport\Application\Services\TransportReportService;
use Modules\Transport\Application\Services\TransportTripService;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportTransaction;
use Tests\Support\TransportScenario;

/*
 * The reconciliation fixture of the brief: one hundred employees over a
 * working week, two buses per direction, deliberate gaps, duplicates, five
 * riders without an assignment and two boardings voided afterwards. The
 * expected figures are derived from the plan, independently of the
 * services, and must match the basis, the statement and the reports to
 * the boarding.
 */
it('reconciles one hundred employees over a week exactly, from plan to statement', function (): void {
    $s = TransportScenario::make();
    $provider = $s->provider('Fixture Lines');
    $route = $s->route($provider);
    $manager = $s->portalUser($provider, 'manager');
    $engine = app(TransportBoardingService::class);
    $trips = app(TransportTripService::class);
    $days = ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'];
    $buses = [$s->vehicle($provider, ['capacity' => 60]), $s->vehicle($provider, ['capacity' => 60])];

    // 100 employees: 60 in Woreda A1, 40 in Subcity B; the first 95 hold an assignment, the last 5 do not.
    $riders = [];
    foreach (range(1, 100) as $i) {
        [$employee, $card] = $s->employee($i <= 60 ? 'woreda_a1' : 'subcity_b', "Fixture {$i}");
        if ($i <= 95) {
            $s->pass($employee, $provider, $route);
        }
        $riders[$i] = [$employee, $card];
    }

    $plan = [];            // [employee => [day => directions boarded]]
    $expectedAccepted = 0;
    $expectedRejected = 0;
    $toVoid = [];
    foreach ($days as $d => $day) {
        foreach (['inbound' => '07:00', 'outbound' => '17:30'] as $direction => $time) {
            Carbon::setTestNow(Carbon::parse("{$day} {$time}"));
            $dayTrips = [$s->trip($route, ['direction' => $direction, 'trip_date' => $day, 'departure_time' => $time, 'transport_vehicle_id' => $buses[0]->id]),
                $s->trip($route, ['direction' => $direction, 'trip_date' => $day, 'departure_time' => $time, 'transport_vehicle_id' => $buses[1]->id])];
            foreach ($riders as $i => [$employee, $card]) {
                $rides = $direction === 'inbound' ? (($i + $d) % 5 !== 0) : (($i * 3 + $d) % 7 !== 0);
                if (! $rides) {
                    continue;
                }
                $trip = $dayTrips[$i % 2];
                $decision = $engine->board(new BoardingRequest(providerId: $provider->id, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
                    credential: $s->qr($card), tripId: $trip->id, idempotencyKey: (string) Str::uuid(), providerUser: $manager));
                if ($i > 95) {
                    expect($decision->accepted)->toBeFalse()->and($decision->resultCode)->toBe('active_transport_pass_missing');
                    $expectedRejected++;

                    continue;
                }
                expect($decision->accepted)->toBeTrue();
                $expectedAccepted++;
                $plan[$i][$day][] = $direction;
                // Three riders scan twice on Monday morning: refused, idempotent on the ledger.
                if ($d === 0 && $direction === 'inbound' && in_array($i, [7, 21, 42], true)) {
                    $again = $engine->board(new BoardingRequest(providerId: $provider->id, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
                        credential: $s->qr($card), tripId: $trip->id, idempotencyKey: (string) Str::uuid(), providerUser: $manager));
                    expect($again->resultCode)->toBe('already_boarded');
                    $expectedRejected++;
                }
                // Two Wednesday evening boardings are voided by staff afterwards (scanned by mistake).
                if ($d === 2 && $direction === 'outbound' && in_array($i, [3, 87], true)) {
                    $toVoid[] = $decision->transaction;
                }
            }
            foreach ($dayTrips as $trip) {
                $trips->complete($trip, $manager);
            }
        }
    }

    Carbon::setTestNow(Carbon::parse('2026-11-02 09:00'));
    $staff = $s->staff(['transport-transactions.void', 'transport-settlements.manage']);
    foreach ($toVoid as $transaction) {
        $engine->void($transaction, $staff, 'Scanned by mistake');
        $day = $transaction->transaction_date->toDateString();
        $i = array_search($transaction->employee_id, array_map(fn ($r) => $r[0]->id, $riders), true);
        $plan[$i][$day] = array_values(array_diff($plan[$i][$day], ['outbound']));
        if ($plan[$i][$day] === []) {
            unset($plan[$i][$day]);
        }
    }

    $expectedVerified = $expectedAccepted - count($toVoid);
    $expectedEmployeeDays = array_sum(array_map(fn (array $byDay): int => count($byDay), $plan));
    $expectedRiders = count($plan);

    // The evidence itself.
    expect(TransportTransaction::query()->verified()->count())->toBe($expectedVerified)
        ->and(TransportTransaction::query()->where('status', 'rejected')->count())->toBe($expectedRejected)
        ->and(TransportTransaction::query()->whereNotNull('voided_at')->count())->toBe(2)
        ->and($expectedVerified)->toBeGreaterThan(700);

    // The settlement basis and the statement under a per-boarding contract.
    $settlements = app(TransportSettlementService::class);
    $from = Carbon::parse('2026-10-01');
    $to = Carbon::parse('2026-10-31');
    $basis = $settlements->basis($provider->id, $from, $to);
    expect($basis['verified_boardings'])->toBe($expectedVerified)
        ->and($basis['unique_riders'])->toBe($expectedRiders)
        ->and($basis['employee_days'])->toBe($expectedEmployeeDays)
        ->and($basis['completed_trips'])->toBe(20)
        ->and($basis['cancelled_trips'])->toBe(0);

    $contract = $s->contract($provider, 'per_verified_boarding', ['rate_per_boarding' => '12.50']);
    $claimedBoardings = $expectedVerified + 50;
    $invoice = app(TransportInvoiceService::class)->draft($provider->id, ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_boardings' => $claimedBoardings, 'claimed_amount' => TransportMoney::format($claimedBoardings * 1250), 'invoice_number' => null], $manager);
    $settlement = $settlements->compute($provider->id, $contract, $from, $to, $staff, $invoice);
    $reconciliation = $settlements->reconciliation($settlement);

    expect((string) $settlement->calculated_amount)->toBe(TransportMoney::format($expectedVerified * 1250))
        ->and($reconciliation['boardings_difference'])->toBe(50)
        ->and(abs((int) TransportMoney::toCents($reconciliation['difference'])))->toBe(50 * 1250)
        ->and($reconciliation['claimed_boardings'])->toBe($claimedBoardings)
        ->and($reconciliation['verified_boardings'])->toBe($expectedVerified)
        ->and(TransportTransaction::query()->where('transport_settlement_id', $settlement->id)->count())->toBe($expectedVerified);

    // The reports agree with the basis: daily usage sums to the same boardings; route utilization sees 95 assigned.
    $reports = app(TransportReportService::class);
    $filters = $reports->normaliseFilters(['date_from' => '2026-10-05', 'date_to' => '2026-10-09']);
    $daily = $reports->dailyUsage($filters, $provider->id);
    expect(array_sum(array_column($daily, 'boardings')))->toBe($expectedVerified)
        ->and(array_sum(array_column($daily, 'completed_trips')))->toBe(20);
    $row = collect($reports->routeUtilization($filters, $provider->id))->firstWhere('route_id', $route->id);
    expect($row['assigned_employees'])->toBe(95)->and($row['unique_riders'])->toBe($expectedRiders)->and($row['boardings'])->toBe($expectedVerified)
        ->and($row['seats_offered'])->toBe(20 * 60);
    // Organization scope: a Subcity B officer's report sees only the 40 riders of Subcity B.
    $officer = $s->staff(['transport-reports.view'], 'subcity_b');
    $scoped = $reports->report($reports->forAdmin($officer, $filters), byProvider: false);
    $expectedB = count(array_filter($plan, fn ($byDay, $i) => $i > 60, ARRAY_FILTER_USE_BOTH));
    expect($scoped['summary']['riders'])->toBe($expectedB);
});
