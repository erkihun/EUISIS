<?php

declare(strict_types=1);

use App\Models\TransportRoute;
use App\Models\TransportTransaction;
use App\Models\TransportTrip;
use App\Models\TransportVehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransportScenario;

/*
 * The provider portal: a provider sees and changes only its own transport
 * records, and each page needs its provider-user permission.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 09:00'));
    $this->s = TransportScenario::make();
    $this->mine = $this->s->provider('Blue Line');
    $this->theirs = $this->s->provider('Red Line');
    $this->manager = $this->s->portalUser($this->mine, 'manager');
    $this->myRoute = $this->s->route($this->mine);
    $this->theirRoute = $this->s->route($this->theirs);
});

it('opens every transport page for a manager', function (string $name, array $params): void {
    $this->actingAs($this->manager, 'provider')->get(route($name, $params))->assertOk();
})->with([
    'dashboard' => ['provider.portal.transport.dashboard', []],
    'scan' => ['provider.portal.transport.scan', []],
    'transactions' => ['provider.portal.transport.transactions.index', []],
    'routes' => ['provider.portal.transport.routes.index', []],
    'new route' => ['provider.portal.transport.routes.create', []],
    'vehicles' => ['provider.portal.transport.vehicles.index', []],
    'new vehicle' => ['provider.portal.transport.vehicles.create', []],
    'drivers' => ['provider.portal.transport.drivers.index', []],
    'new driver' => ['provider.portal.transport.drivers.create', []],
    'trips' => ['provider.portal.transport.trips.index', []],
    'new trip' => ['provider.portal.transport.trips.create', []],
    'reports' => ['provider.portal.transport.reports.index', []],
]);

it('keeps an operator to the pages their permissions name', function (): void {
    $operator = $this->s->portalUser($this->mine, 'operator', ['provider.transport.scan']);

    $this->actingAs($operator, 'provider')->get(route('provider.portal.transport.scan'))->assertOk();
    foreach (['routes.index', 'routes.create', 'vehicles.index', 'drivers.create', 'trips.index', 'transactions.index', 'transactions.export.csv', 'reports.index'] as $page) {
        $this->actingAs($operator, 'provider')->get(route("provider.portal.transport.{$page}"))->assertForbidden();
    }

    // The navigation offers only what the routes allow.
    $this->actingAs($operator, 'provider')->get(route('provider.portal.transport.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('providerPortal.can.transportScan', true)
            ->where('providerPortal.can.transportRoutesManage', false)
            ->where('providerPortal.can.transportTransactionsView', false));
});

it('cannot move its route to another provider or open the route to the whole city', function (): void {
    $this->actingAs($this->manager, 'provider')->patch(route('provider.portal.transport.routes.update', $this->myRoute), [
        'provider_id' => $this->theirs->id, 'route_code' => $this->myRoute->route_code, 'name_en' => 'Moved',
        'origin_en' => 'A', 'destination_en' => 'B', 'assigned_scope_type' => 'citywide',
    ])->assertSessionHasErrors(['provider_id', 'assigned_scope_type']);

    expect($this->myRoute->fresh()->provider_id)->toBe($this->mine->id);
});

it("cannot edit another provider's route", function (): void {
    $this->actingAs($this->manager, 'provider')->get(route('provider.portal.transport.routes.edit', $this->theirRoute))->assertForbidden();
    $this->actingAs($this->manager, 'provider')->patch(route('provider.portal.transport.routes.update', $this->theirRoute), [
        'route_code' => 'X', 'name_en' => 'Taken', 'origin_en' => 'A', 'destination_en' => 'B',
    ])->assertForbidden();

    expect($this->theirRoute->fresh()->name_en)->not->toBe('Taken');
});

it("cannot plan a trip on another provider's route, vehicle or driver", function (): void {
    $theirVehicle = TransportVehicle::query()->create(['provider_id' => $this->theirs->id, 'vehicle_code' => 'V-1', 'plate_number' => 'AA-1-0001', 'status' => 'active']);

    $this->actingAs($this->manager, 'provider')->post(route('provider.portal.transport.trips.store'), [
        'transport_route_id' => $this->theirRoute->id, 'transport_vehicle_id' => $theirVehicle->id,
        'trip_date' => '2026-10-15', 'status' => 'scheduled',
    ])->assertSessionHasErrors(['transport_route_id', 'transport_vehicle_id']);

    expect(TransportTrip::query()->count())->toBe(0);
});

it('numbers trips without collisions and round-trips their times', function (): void {
    foreach (range(1, 3) as $i) {
        $this->actingAs($this->manager, 'provider')->post(route('provider.portal.transport.trips.store'), [
            'transport_route_id' => $this->myRoute->id, 'trip_date' => '2026-10-15', 'departure_time' => '07:30', 'arrival_time' => '08:15', 'status' => 'scheduled',
        ])->assertSessionHasNoErrors();
    }
    TransportTrip::query()->first()->delete();
    $this->actingAs($this->manager, 'provider')->post(route('provider.portal.transport.trips.store'), [
        'transport_route_id' => $this->myRoute->id, 'trip_date' => '2026-10-15', 'status' => 'scheduled',
    ])->assertSessionHasNoErrors();

    $trip = TransportTrip::query()->latest('created_at')->first();
    expect(TransportTrip::query()->distinct()->count('trip_number'))->toBe(3);

    // The edit form receives HH:MM and can send it straight back, trip number included.
    $edit = TransportTrip::query()->whereNotNull('departure_time')->first();
    $this->actingAs($this->manager, 'provider')->get(route('provider.portal.transport.trips.edit', $edit))
        ->assertInertia(fn (Assert $page) => $page->where('trip.departure_time', '07:30')->where('trip.arrival_time', '08:15'));
    $this->actingAs($this->manager, 'provider')->patch(route('provider.portal.transport.trips.update', $edit), [
        'transport_route_id' => $this->myRoute->id, 'trip_number' => $edit->trip_number, 'trip_date' => '2026-10-15',
        'departure_time' => '07:30', 'arrival_time' => '08:15', 'status' => 'boarding',
    ])->assertSessionHasNoErrors();
    expect($edit->fresh()->status)->toBe('boarding')->and($trip)->not->toBeNull();
});

it('refuses an arrival before the departure', function (): void {
    $this->actingAs($this->manager, 'provider')->post(route('provider.portal.transport.trips.store'), [
        'transport_route_id' => $this->myRoute->id, 'trip_date' => '2026-10-15', 'departure_time' => '09:00', 'arrival_time' => '08:00', 'status' => 'scheduled',
    ])->assertSessionHasErrors('arrival_time');
});

it('reports a duplicate route code or plate as a validation error', function (): void {
    $this->actingAs($this->manager, 'provider')->post(route('provider.portal.transport.routes.store'), [
        'route_code' => $this->myRoute->route_code, 'name_en' => 'Twin', 'origin_en' => 'A', 'destination_en' => 'B',
    ])->assertSessionHasErrors('route_code');

    // Another provider may reuse the code.
    $this->actingAs($this->manager, 'provider')->post(route('provider.portal.transport.routes.store'), [
        'route_code' => $this->theirRoute->route_code, 'name_en' => 'Same code, other provider', 'origin_en' => 'A', 'destination_en' => 'B',
    ])->assertSessionHasNoErrors();

    TransportVehicle::query()->create(['provider_id' => $this->theirs->id, 'vehicle_code' => 'V-1', 'plate_number' => 'AA-2-0002', 'status' => 'active']);
    $this->actingAs($this->manager, 'provider')->post(route('provider.portal.transport.vehicles.store'), [
        'vehicle_code' => 'V-1', 'plate_number' => 'AA-2-0002', 'status' => 'active',
    ])->assertSessionHasErrors('plate_number');

    expect(TransportRoute::query()->where('provider_id', $this->mine->id)->count())->toBe(2);
});

it('lists and exports only its own transactions, filtered by date', function (): void {
    [$employee, $card] = $this->s->employee();
    $make = fn ($provider, string $date) => TransportTransaction::query()->create([
        'provider_id' => $provider->id, 'employee_id' => $employee->id, 'id_card_id' => $card->id, 'scanned_at' => $date.' 07:00',
        'transaction_date' => $date, 'status' => 'accepted', 'result_code' => 'transport_scan_accepted',
    ]);
    $make($this->mine, '2026-10-01');
    $make($this->mine, '2026-10-14');
    $make($this->theirs, '2026-10-14');

    $this->actingAs($this->manager, 'provider')->get(route('provider.portal.transport.transactions.index', ['date_from' => '2026-10-10']))
        ->assertInertia(fn (Assert $page) => $page->where('transactions.meta.total', 1)->where('filters.date_from', '2026-10-10'));

    $csv = $this->actingAs($this->manager, 'provider')->get(route('provider.portal.transport.transactions.export.csv'))->streamedContent();
    expect(substr_count(trim($csv), "\n"))->toBe(2); // header + my two rows

    $this->actingAs($this->manager, 'provider')->get(route('provider.portal.transport.reports.index', ['date_from' => '2026-10-01', 'date_to' => '2026-10-14']))
        ->assertInertia(fn (Assert $page) => $page->where('report.summary.total', 2)->where('report.summary.riders', 1));
});

it('lets a new transport-only account replace its one-time password and reach the dashboard', function (): void {
    $owner = $this->s->portalUser($this->mine, 'owner');
    $owner->forceFill(['must_change_password' => true, 'password' => Hash::make('Temp#Pass-2026')])->save();

    // Every transport page sends the user to the profile first, and the profile opens.
    $this->actingAs($owner, 'provider')->get(route('provider.portal.transport.dashboard'))->assertRedirect(route('provider.portal.profile.show'));
    $this->actingAs($owner, 'provider')->get(route('provider.portal.profile.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ProviderPortal/Transport/Profile'));

    $this->actingAs($owner, 'provider')->patch(route('provider.portal.profile.password'), [
        'current_password' => 'Temp#Pass-2026', 'password' => 'Blue-Line#Route-2026', 'password_confirmation' => 'Blue-Line#Route-2026',
    ])->assertSessionHasNoErrors()->assertRedirect(route('provider.portal.transport.dashboard'));

    expect($owner->fresh()->must_change_password)->toBeFalse();
    $this->actingAs($owner->fresh(), 'provider')->get(route('provider.portal.transport.dashboard'))->assertOk();
});
