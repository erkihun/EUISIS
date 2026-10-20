<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use App\Models\TransportTransaction;
use App\Services\Transport\TransportSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TransportScenario;

/*
 * The boarding scan, through the provider portal: who may board, the replay
 * guard, trips and the scan settings. Every case reads the stored
 * transaction, not just the response.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 07:15'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->operator = $this->s->portalUser($this->provider, 'operator', ['provider.transport.scan']);
    $this->route = $this->s->route($this->provider);
    // Every boarding sits inside an activated trip on a vehicle: the default scan names today's boarding trip.
    $this->trip = $this->s->trip($this->route);
    [$this->employee, $this->card] = $this->s->employee('woreda_a1');

    $this->scan = function (array $extra = [], ?string $nonce = null) {
        return $this->actingAs($this->operator, 'provider')->postJson(route('provider.portal.transport.scan.store'), $extra + [
            'qr_token' => $this->s->qr($this->card),
            'scan_nonce' => $nonce ?? (string) Str::uuid(),
            'transport_trip_id' => $this->trip->id,
        ]);
    };
    $this->setting = function (string $key, bool $value): void {
        SystemSetting::query()->updateOrCreate(['group' => 'transport', 'key' => $key], [
            'value' => $value ? '1' : '0', 'type' => 'boolean', 'label_en' => $key, 'label_am' => $key,
            'default_value' => '0', 'is_public' => false, 'is_encrypted' => false, 'is_system' => false, 'is_required' => true,
        ]);
        app(TransportSettings::class)->forget();
    };
});

it('boards an employee with an active pass and records who scanned it', function (): void {
    $pass = $this->s->pass($this->employee, $this->provider, $this->route);

    ($this->scan)()->assertCreated()->assertJsonPath('accepted', true)->assertJsonPath('result_code', 'transport_scan_accepted');

    $transaction = TransportTransaction::query()->sole();
    expect($transaction->status)->toBe('accepted')
        ->and($transaction->transport_pass_id)->toBe($pass->id)
        ->and($transaction->provider_id)->toBe($this->provider->id)
        ->and($transaction->scanned_by_provider_user_id)->toBe($this->operator->id)
        // Only a hash of the scanned value is kept, never the value itself.
        ->and($transaction->qr_reference_hash)->toBe(hash('sha256', $this->s->qr($this->card)));
});

it('refuses a boarding without a pass, with a reason the operator can read', function (): void {
    ($this->scan)()->assertStatus(422)
        ->assertJsonPath('accepted', false)
        ->assertJsonPath('result_code', 'active_transport_pass_missing')
        ->assertJsonPath('message', __('transport.reasons.active_transport_pass_missing'));

    expect(TransportTransaction::query()->sole()->status)->toBe('rejected');
});

it('ignores passes that expired or are suspended', function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route, ['valid_until' => today()->subDay()->toDateString()]);
    $this->s->pass($this->employee, $this->provider, $this->route, ['status' => 'suspended']);

    ($this->scan)()->assertStatus(422)->assertJsonPath('result_code', 'active_transport_pass_missing');
});

it('applies the alternative-route policy to a pass that names another route', function (): void {
    $this->s->pass($this->employee, $this->provider, $this->s->route($this->provider));

    // Default: refused, and the refusal is evidence with the assigned route in its snapshot.
    ($this->scan)()->assertStatus(422)->assertJsonPath('result_code', 'route_not_assigned');
    expect(TransportTransaction::query()->sole()->eligibility_snapshot['assigned_route_id'])->not->toBe($this->route->id);

    SystemSetting::query()->updateOrCreate(['group' => 'transport', 'key' => 'alternative_route_policy'], [
        'value' => 'allow_same_provider', 'type' => 'string', 'label_en' => 'x', 'label_am' => 'x', 'default_value' => 'deny',
        'is_public' => false, 'is_encrypted' => false, 'is_system' => false, 'is_required' => true,
    ]);
    app(TransportSettings::class)->forget();
    ($this->scan)()->assertCreated();
    expect(TransportTransaction::query()->where('status', 'accepted')->sole()->metadata['alternative_route'])->toBeTrue();
});

it('dates the scan by the server clock, whatever the device claims', function (): void {
    // The pass ended yesterday; a device claiming yesterday must not revive it.
    $this->s->pass($this->employee, $this->provider, $this->route, ['valid_until' => today()->subDay()->toDateString()]);

    ($this->scan)(['scanned_at' => today()->subDay()->setTime(8, 0)->toIso8601String()])->assertStatus(422);

    $transaction = TransportTransaction::query()->sole();
    expect($transaction->transaction_date->toDateString())->toBe('2026-10-14')
        ->and($transaction->metadata['client_scanned_at'])->not->toBeNull();
});

it('replays a repeated nonce without a second transaction', function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route);
    $nonce = (string) Str::uuid();

    ($this->scan)([], $nonce)->assertCreated();
    ($this->scan)([], $nonce)->assertCreated()->assertJsonPath('result_code', 'duplicate_scan_nonce');

    expect(TransportTransaction::query()->count())->toBe(1);
});

it("never reveals another provider's transaction through a reused nonce", function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route);
    $nonce = (string) Str::uuid();
    ($this->scan)([], $nonce)->assertCreated();

    $rival = $this->s->provider('Rival');
    $rivalOperator = $this->s->portalUser($rival, 'operator', ['provider.transport.scan']);

    $rivalTrip = $this->s->trip($this->s->route($rival));
    $this->actingAs($rivalOperator, 'provider')->postJson(route('provider.portal.transport.scan.store'), [
        'qr_token' => $this->s->qr($this->card), 'scan_nonce' => $nonce, 'transport_trip_id' => $rivalTrip->id,
    ])->assertStatus(422)->assertJsonPath('result_code', 'scan_nonce_conflict')->assertJsonPath('transaction', null);
});

it("refuses another provider's route and trip", function (): void {
    $foreignRoute = $this->s->route($this->s->provider('Rival'));
    $foreignTrip = $this->s->trip($foreignRoute);

    ($this->scan)(['transport_trip_id' => null, 'transport_route_id' => $foreignRoute->id])->assertStatus(422)->assertJsonPath('result_code', 'route_not_found');
    ($this->scan)(['transport_trip_id' => $foreignTrip->id])->assertStatus(422)->assertJsonPath('result_code', 'trip_not_found');
    expect(TransportTransaction::query()->count())->toBe(0);
});

it('requires an activated trip unless the settings say otherwise', function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route);

    ($this->scan)(['transport_trip_id' => null, 'transport_route_id' => $this->route->id])->assertStatus(422)->assertJsonPath('result_code', 'trip_required');
    expect(TransportTransaction::query()->count())->toBe(0);

    ($this->setting)('require_trip_for_boarding', false);
    ($this->scan)(['transport_trip_id' => null, 'transport_route_id' => $this->route->id])->assertCreated();
});

it('lets a route serve its own office, its subtree or the whole city', function (string $scope, string $routeOrg, bool $boards): void {
    $route = $this->s->route($this->provider, ['assigned_organization_id' => $this->s->org[$routeOrg]->id, 'assigned_scope_type' => $scope]);
    $this->s->pass($this->employee, $this->provider, $route);

    $response = ($this->scan)(['transport_trip_id' => $this->s->trip($route)->id]);

    $boards
        ? $response->assertCreated()
        : $response->assertStatus(422)->assertJsonPath('result_code', 'route_not_allowed_for_employee');
})->with([
    'self, same office' => ['self', 'woreda_a1', true],
    'self, parent office' => ['self', 'subcity_a', false],
    'subtree from the parent' => ['subtree', 'subcity_a', true],
    'subtree from the bureau' => ['subtree', 'bureau', true],
    'subtree of a sibling' => ['subtree', 'subcity_b', false],
]);

it('refuses an inactive route', function (): void {
    $this->route->update(['is_active' => false]);
    $this->s->pass($this->employee, $this->provider, $this->route);

    ($this->scan)()->assertStatus(422)->assertJsonPath('result_code', 'route_inactive');
});

it('counts a boarding against its trip and refuses a second boarding of the same person', function (): void {
    $trip = $this->s->trip($this->route, ['capacity' => 40]);
    $this->s->pass($this->employee, $this->provider, $this->route);

    // The trip names its route; the scan need not repeat it.
    ($this->scan)(['transport_trip_id' => $trip->id])->assertCreated();
    ($this->scan)(['transport_trip_id' => $trip->id])->assertStatus(422)->assertJsonPath('result_code', 'already_boarded');

    expect($trip->fresh()->boarded_count)->toBe(1)
        ->and(TransportTransaction::query()->where('status', 'accepted')->value('transport_route_id'))->toBe($this->route->id);
});

it('refuses a full trip', function (): void {
    $trip = $this->s->trip($this->route, ['capacity' => 1]);
    [$other, $otherCard] = $this->s->employee('woreda_a1', 'Almaz Tesfaye');
    $this->s->pass($this->employee, $this->provider, $this->route);
    $this->s->pass($other, $this->provider, $this->route);

    ($this->scan)(['transport_trip_id' => $trip->id])->assertCreated();
    $this->card = $otherCard;
    ($this->scan)(['transport_trip_id' => $trip->id])->assertStatus(422)->assertJsonPath('result_code', 'trip_full');

    expect($trip->fresh()->boarded_count)->toBe(1);
});

it('refuses trips that are not boarding today or run on another route', function (array $trip, array $scan, string $code): void {
    $this->s->pass($this->employee, $this->provider, null);
    $other = $this->s->route($this->provider);
    $trip = $this->s->trip($this->route, $trip);

    ($this->scan)(['transport_trip_id' => $trip->id, 'transport_route_id' => ($scan['route'] ?? null) === 'other' ? $other->id : $this->route->id])
        ->assertStatus(422)->assertJsonPath('result_code', $code);
})->with([
    'not started' => [['status' => 'planned'], [], 'trip_not_boardable'],
    'completed' => [['status' => 'completed'], [], 'trip_not_boardable'],
    'cancelled' => [['status' => 'cancelled'], [], 'trip_not_boardable'],
    'tomorrow' => [['trip_date' => '2026-10-15'], [], 'trip_not_today'],
    'another route' => [[], ['route' => 'other'], 'trip_route_mismatch'],
]);

it('boards while the trip is in progress, since employees join at later stops', function (): void {
    $this->trip->update(['status' => 'in_progress']);
    $this->s->pass($this->employee, $this->provider, $this->route);

    ($this->scan)()->assertCreated();
});

it('accepts a boarding without a pass as pay-as-you-go when the settings allow it', function (): void {
    ($this->setting)('allow_pay_as_you_go', true);

    ($this->scan)()->assertCreated()->assertJsonPath('result_code', 'pay_as_you_go');
    expect(TransportTransaction::query()->sole())->status->toBe('accepted')->transport_pass_id->toBeNull();
});

it('accepts a boarding without a pass when passes are not required', function (): void {
    ($this->setting)('require_pass_for_scan', false);

    ($this->scan)()->assertCreated()->assertJsonPath('result_code', 'pass_not_required');
});

it('makes the nonce optional only when the settings say so', function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route);
    $payload = ['qr_token' => $this->s->qr($this->card), 'transport_trip_id' => $this->trip->id];

    $this->actingAs($this->operator, 'provider')->postJson(route('provider.portal.transport.scan.store'), $payload)->assertJsonValidationErrors('scan_nonce');

    ($this->setting)('scan_nonce_required', false);
    $this->actingAs($this->operator, 'provider')->postJson(route('provider.portal.transport.scan.store'), $payload)->assertCreated();
    expect(TransportTransaction::query()->sole()->scan_nonce)->not->toBeNull();
});

it('refuses an unknown card without recording a boarding', function (): void {
    $this->actingAs($this->operator, 'provider')->postJson(route('provider.portal.transport.scan.store'), [
        'qr_token' => 'https://example.test/not-a-card-at-all', 'scan_nonce' => (string) Str::uuid(), 'transport_trip_id' => $this->trip->id,
    ])->assertStatus(422)->assertJsonPath('result_code', 'card_not_found');

    expect(TransportTransaction::query()->count())->toBe(0);
});

it('needs the scan permission', function (): void {
    $viewer = $this->s->portalUser($this->provider, 'operator', ['provider.transport.reports.view']);

    $this->actingAs($viewer, 'provider')->get(route('provider.portal.transport.scan'))->assertForbidden();
    $this->actingAs($viewer, 'provider')->postJson(route('provider.portal.transport.scan.store'), [
        'qr_token' => $this->s->qr($this->card), 'scan_nonce' => (string) Str::uuid(),
    ])->assertForbidden();
});

it("lists today's boardings for the terminal, this provider only, a trip narrowing them", function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route);
    ($this->scan)()->assertCreated();
    // A refusal is listed too: the operator sees what the terminal did today.
    [$other, $otherCard] = $this->s->employee('woreda_a1', 'Hana Tadesse');
    ($this->scan)(['qr_token' => $this->s->qr($otherCard)])->assertStatus(422);
    // Another provider's boarding never appears.
    $rival = $this->s->provider('Rival');
    $rivalTrip = $this->s->trip($this->s->route($rival));
    $this->actingAs($this->s->portalUser($rival, 'operator', ['provider.transport.scan']), 'provider')
        ->postJson(route('provider.portal.transport.scan.store'), ['qr_token' => $this->s->qr($this->card), 'scan_nonce' => (string) Str::uuid(), 'transport_trip_id' => $rivalTrip->id]);

    $portal = $this->actingAs($this->operator, 'provider')->getJson(route('provider.portal.transport.scan.today'))->assertOk();
    $rows = collect($portal->json('data'));
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->sort()->values()->all())->toBe(['accepted', 'rejected'])
        ->and($rows->pluck('employee.employee_number')->sort()->values()->all())->toBe(collect([$this->employee->employee_number, $other->employee_number])->sort()->values()->all())
        ->and($rows->firstWhere('status', 'accepted')['trip']['trip_number'])->toBe($this->trip->trip_number);

    $this->actingAs($this->operator, 'provider')->getJson(route('provider.portal.transport.scan.today', ['transport_trip_id' => $rivalTrip->id]))
        ->assertOk()->assertJsonCount(0, 'data');

    $staff = $this->s->staff(['transport-scan.create']);
    $this->actingAs($staff)->getJson(route('transport.scan.today', ['provider_id' => $this->provider->id]))->assertOk()->assertJsonCount(2, 'data');
    $this->actingAs($staff)->getJson(route('transport.scan.today', ['provider_id' => $rival->id]))->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($staff)->getJson(route('transport.scan.today', ['provider_id' => 'not-a-uuid']))->assertUnprocessable();
    $this->actingAs($this->s->staff([]))->getJson(route('transport.scan.today', ['provider_id' => $this->provider->id]))->assertForbidden();
});

it('lets staff scan for a provider from the admin terminal', function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route);
    $staff = $this->s->staff(['transport-scan.create']);

    $this->actingAs($staff)->postJson(route('transport.scan.store'), [
        'provider_id' => $this->provider->id, 'qr_token' => $this->s->qr($this->card),
        'scan_nonce' => (string) Str::uuid(), 'transport_trip_id' => $this->trip->id,
    ])->assertCreated();

    expect(TransportTransaction::query()->sole()->metadata['source'])->toBe('admin_transport_scan');

    $this->actingAs($this->s->staff([]))->postJson(route('transport.scan.store'), [
        'provider_id' => $this->provider->id, 'qr_token' => $this->s->qr($this->card), 'scan_nonce' => (string) Str::uuid(),
    ])->assertForbidden();
});
