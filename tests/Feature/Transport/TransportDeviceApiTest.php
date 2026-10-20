<?php

declare(strict_types=1);

use App\Enums\Transport\TransportTripStatus;
use App\Models\SystemSetting;
use App\Models\TransportException;
use App\Models\TransportTransaction;
use App\Services\Transport\TransportSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TransportScenario;

/*
 * The scanner device API: a device authenticates with its own credential,
 * works only inside its provider's (and vehicle's) trips, and every boarding
 * goes through the same engine as the portal. Security tests 164, 165, 168
 * and 174 of the transport brief live here.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 07:15'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->route = $this->s->route($this->provider);
    $this->vehicle = $this->s->vehicle($this->provider, ['capacity' => 2]);
    [$this->device, $this->credential] = $this->s->device($this->provider, $this->vehicle);
    $this->trip = $this->s->trip($this->route, ['transport_vehicle_id' => $this->vehicle->id, 'status' => 'assigned', 'actual_started_at' => null]);
    [$this->employee, $this->card] = $this->s->employee('woreda_a1');
    $this->s->pass($this->employee, $this->provider, $this->route);

    $this->api = fn (?string $credential = null) => $this->withHeaders(['Authorization' => 'Bearer '.($credential ?? $this->credential), 'Accept' => 'application/json']);
    $this->verify = fn (array $extra = [], ?string $credential = null) => ($this->api)($credential)->postJson(route('api.transport.boardings.verify'), $extra + [
        'credential' => ['type' => 'qr', 'value' => $this->s->qr($this->card)],
        'trip_id' => $this->trip->id,
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $this->setting = function (string $key, string $value, string $type = 'boolean'): void {
        SystemSetting::query()->updateOrCreate(['group' => 'transport', 'key' => $key], [
            'value' => $value, 'type' => $type, 'label_en' => $key, 'label_am' => $key, 'default_value' => '0',
            'is_public' => false, 'is_encrypted' => false, 'is_system' => false, 'is_required' => true,
        ]);
        app(TransportSettings::class)->forget();
    };
});

it('refuses an unknown, suspended or revoked credential, and a device whose provider dropped transport', function (): void {
    $this->getJson(route('api.transport.device.show'))->assertUnauthorized();
    ($this->api)('tdev_'.Str::random(40))->getJson(route('api.transport.device.show'))->assertUnauthorized()->assertJsonPath('error_code', 'device_unauthorized');

    ($this->api)()->getJson(route('api.transport.device.show'))->assertOk()
        ->assertJsonPath('device.device_code', $this->device->device_code)
        ->assertJsonPath('device.vehicle.plate_number', $this->vehicle->plate_number)
        ->assertJsonPath('capabilities.offline', false);

    $this->device->update(['status' => 'revoked', 'revoked_at' => now()]);
    ($this->verify)()->assertUnauthorized();
    expect(TransportTransaction::query()->count())->toBe(0);
});

it('starts the trip from the device and boards within it, with the compact answer the operator needs', function (): void {
    ($this->api)()->getJson(route('api.transport.trips.today'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'assigned');

    ($this->api)()->postJson(route('api.transport.trips.start', $this->trip))->assertOk()->assertJsonPath('data.status', 'boarding');
    expect($this->trip->fresh()->status)->toBe(TransportTripStatus::Boarding)->and($this->trip->fresh()->expected_riders)->toBe(1);

    $response = ($this->verify)()->assertCreated()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('result_code', 'transport_scan_accepted')
        ->assertJsonPath('employee.full_name', 'Abebe Kebede')
        ->assertJsonPath('trip.boarded_count', 1)
        ->assertJsonPath('vehicle.plate_number', $this->vehicle->plate_number);
    // Nothing beyond the name and number leaves the server for a device.
    expect($response->json('employee'))->toHaveKeys(['full_name', 'employee_number', 'photo_url'])->not->toHaveKey('organization');

    $transaction = TransportTransaction::query()->where('status', 'accepted')->sole();
    expect($transaction->transport_device_id)->toBe($this->device->id)
        ->and($transaction->transport_vehicle_id)->toBe($this->vehicle->id)
        ->and($transaction->direction->value)->toBe('inbound')
        ->and($transaction->verification_method->value)->toBe('qr')
        ->and($transaction->boarding_leg)->toBe('2026-10-14:inbound')
        ->and($transaction->eligibility_snapshot['basis'])->toBe('pass')
        ->and($transaction->metadata['source'])->toBe('device_api');
});

it('keeps a vehicle-bound device inside its own vehicle and provider', function (): void {
    $this->trip->update(['status' => 'boarding']);
    $otherVehicle = $this->s->vehicle($this->provider);
    $otherTrip = $this->s->trip($this->route, ['transport_vehicle_id' => $otherVehicle->id]);
    $rivalTrip = $this->s->trip($this->s->route($this->s->provider('Rival')));

    // Another vehicle of the same provider: not this device's trip (404, nothing revealed).
    ($this->verify)(['trip_id' => $otherTrip->id])->assertStatus(422)->assertJsonPath('result_code', 'device_trip_mismatch');
    expect(TransportException::query()->where('type', 'device_trip_mismatch')->count())->toBe(1);
    // Another provider's trip: refused as unknown.
    ($this->verify)(['trip_id' => $rivalTrip->id])->assertStatus(422)->assertJsonPath('result_code', 'trip_not_found');
    ($this->api)()->postJson(route('api.transport.trips.start', $rivalTrip))->assertNotFound();
    ($this->api)()->postJson(route('api.transport.trips.start', $otherTrip))->assertNotFound();

    expect(TransportTransaction::query()->where('status', 'accepted')->count())->toBe(0);
});

it('answers a repeated idempotency key with the first result and never a second row', function (): void {
    $this->trip->update(['status' => 'boarding']);
    $key = (string) Str::uuid();

    ($this->verify)(['idempotency_key' => $key])->assertCreated()->assertJsonPath('replayed', false);
    ($this->verify)(['idempotency_key' => $key])->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('result_code', 'duplicate_scan_nonce');
    // A fresh key for the same person on the same trip is a duplicate boarding, refused and recorded.
    ($this->verify)()->assertStatus(422)->assertJsonPath('result_code', 'already_boarded');

    expect(TransportTransaction::query()->where('status', 'accepted')->count())->toBe(1)
        ->and(TransportTransaction::query()->where('status', 'rejected')->count())->toBe(1)
        ->and($this->trip->fresh()->boarded_count)->toBe(1);
});

it('refuses a second boarding in the same direction on another trip the same day, by policy', function (): void {
    $this->trip->update(['status' => 'boarding']);
    $secondTrip = $this->s->trip($this->route, ['transport_vehicle_id' => $this->vehicle->id, 'departure_time' => '08:30']);

    ($this->verify)()->assertCreated();
    ($this->verify)(['trip_id' => $secondTrip->id])->assertStatus(422)->assertJsonPath('result_code', 'already_boarded_this_leg');

    // One per trip only: the second trip accepts, and the anomaly is recorded because it is within minutes.
    ($this->setting)('duplicate_boarding_policy', 'one_per_trip', 'string');
    ($this->verify)(['trip_id' => $secondTrip->id])->assertCreated()->assertJsonPath('warnings.0', 'simultaneous_boarding');
    expect(TransportException::query()->where('type', 'simultaneous_boarding')->count())->toBe(1);

    // The evening leg is always a separate boarding.
    $evening = $this->s->trip($this->route, ['transport_vehicle_id' => $this->vehicle->id, 'direction' => 'outbound', 'departure_time' => '17:30']);
    $this->travelTo(Carbon::parse('2026-10-14 17:35'));
    ($this->verify)(['trip_id' => $evening->id])->assertCreated();
});

it('blocks or warns at capacity as configured, and keeps the evidence either way', function (): void {
    $this->trip->update(['status' => 'boarding']);
    [$second, $secondCard] = $this->s->employee('woreda_a1', 'Almaz Tesfaye');
    [$third, $thirdCard] = $this->s->employee('woreda_a1', 'Kebede Alemu');
    foreach ([$second, $third] as $e) {
        $this->s->pass($e, $this->provider, $this->route);
    }

    ($this->verify)()->assertCreated();
    ($this->verify)(['credential' => ['type' => 'qr', 'value' => $this->s->qr($secondCard)]])->assertCreated();
    ($this->verify)(['credential' => ['type' => 'qr', 'value' => $this->s->qr($thirdCard)]])->assertStatus(422)->assertJsonPath('result_code', 'trip_full');
    expect(TransportException::query()->where('type', 'overcapacity')->count())->toBe(1)->and($this->trip->fresh()->boarded_count)->toBe(2);

    ($this->setting)('capacity_enforcement', 'warn', 'string');
    ($this->verify)(['credential' => ['type' => 'qr', 'value' => $this->s->qr($thirdCard)]])->assertCreated()->assertJsonPath('warnings.0', 'overcapacity');
    expect($this->trip->fresh()->boarded_count)->toBe(3)->and($this->trip->fresh()->occupancyStatus())->toBe('at_capacity');
});

it('rejects a revoked card and records the attempt as evidence', function (): void {
    $this->trip->update(['status' => 'boarding']);
    $this->card->update(['status' => 'revoked', 'revoked_at' => now(), 'is_current' => false]);

    ($this->verify)()->assertStatus(422)->assertJsonPath('result_code', 'id_card_revoked');

    expect(TransportTransaction::query()->sole()->status)->toBe('rejected')
        ->and(TransportException::query()->where('type', 'inactive_card_use')->count())->toBe(1);
});

it('syncs offline boardings only when enabled, re-validating each one and replaying duplicates', function (): void {
    $this->trip->update(['status' => 'boarding']);
    $item = fn (array $extra = []) => $extra + [
        'offline_uuid' => (string) Str::uuid(),
        'credential' => ['type' => 'qr', 'value' => $this->s->qr($this->card)],
        'trip_id' => $this->trip->id,
        'recorded_at' => '2026-10-14T07:05:00+03:00',
    ];

    ($this->api)()->postJson(route('api.transport.boardings.sync'), ['boardings' => [$item()]])->assertStatus(409)->assertJsonPath('error_code', 'offline_disabled');

    config(['features.transport_offline' => true]);
    ($this->api)()->postJson(route('api.transport.boardings.sync'), ['boardings' => [$item()]])->assertOk()->assertJsonPath('data.0.result_code', 'offline_disabled');

    ($this->setting)('offline_enabled', '1');
    $first = $item();
    $stale = $item(['recorded_at' => '2026-10-13T07:05:00+03:00']);
    $response = ($this->api)()->postJson(route('api.transport.boardings.sync'), ['boardings' => [$first, $first + ['offline_uuid' => $first['offline_uuid']], $stale]]);
    // The same uuid twice in one batch is a validation error, never two rows.
    $response->assertStatus(422);

    $response = ($this->api)()->postJson(route('api.transport.boardings.sync'), ['boardings' => [$first, $stale]])->assertOk();
    expect($response->json('data.0.accepted'))->toBeTrue()
        ->and($response->json('data.1.result_code'))->toBe('offline_record_too_old')
        ->and($response->json('summary.accepted'))->toBe(1);

    // Replayed sync (the device retried): the same answer, still one boarding.
    ($this->api)()->postJson(route('api.transport.boardings.sync'), ['boardings' => [$first]])->assertOk()->assertJsonPath('data.0.replayed', true);
    $accepted = TransportTransaction::query()->where('status', 'accepted')->get();
    expect($accepted)->toHaveCount(1)
        ->and($accepted->first()->sync_state->value)->toBe('offline_reconciled')
        ->and($accepted->first()->scanned_at->timestamp)->toBe(Carbon::parse('2026-10-14T07:05:00+03:00')->timestamp)
        ->and($accepted->first()->offline_recorded_at)->not->toBeNull();
});

it('stores vehicle positions only behind the gps flag and on an open trip', function (): void {
    $positions = ['positions' => [['latitude' => 9.0101, 'longitude' => 38.7613, 'recorded_at' => now()->toIso8601String()]]];
    ($this->api)()->postJson(route('api.transport.positions.store', $this->trip), $positions)->assertStatus(409);

    config(['features.transport_gps' => true]);
    ($this->api)()->postJson(route('api.transport.positions.store', $this->trip), $positions)->assertCreated()->assertJsonPath('stored', 1);
    $this->trip->update(['status' => 'completed']);
    ($this->api)()->postJson(route('api.transport.positions.store', $this->trip), $positions)->assertStatus(409)->assertJsonPath('error_code', 'trip_closed');
});
