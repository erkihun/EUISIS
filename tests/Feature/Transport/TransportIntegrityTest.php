<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Transport\Application\Services\Boarding\BoardingLock;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Tests\Support\TransportScenario;

/*
 * The database is the last line against a double boarding: whatever the
 * application does, a second accepted, unvoided boarding of the same
 * employee on the same trip cannot be stored. On PostgreSQL the engine also
 * serialises boardings per employee with an advisory lock.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 07:10'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->route = $this->s->route($this->provider);
    $this->trip = $this->s->trip($this->route);
    [$this->employee, $this->card] = $this->s->employee();
    $this->s->pass($this->employee, $this->provider, $this->route);
});

it('refuses a second accepted boarding row for the same employee and trip at the database', function (): void {
    $decision = app(TransportBoardingService::class)->board(new BoardingRequest(providerId: $this->provider->id, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
        credential: $this->s->qr($this->card), tripId: $this->trip->id, idempotencyKey: (string) Str::uuid(), providerUser: $this->s->portalUser($this->provider, 'manager')));
    $row = (array) DB::table('transport_transactions')->where('id', $decision->transaction->id)->first();
    $row['id'] = (string) Str::uuid();
    // MySQL/MariaDB derive the boarding key themselves (a VIRTUAL generated column); it cannot be written.
    unset($row['ttx_boarding_key']);
    foreach ($row as $column => $value) {
        if (str_contains($column, 'nonce') || str_contains($column, 'idempotency') || str_contains($column, 'offline_uuid')) {
            $row[$column] = $value === null ? null : (string) Str::uuid();
        }
    }

    expect(fn () => DB::table('transport_transactions')->insert($row))->toThrow(QueryException::class);

    // A voided row releases the slot: the same employee may be boarded again on the trip after a mistaken scan is voided.
    DB::table('transport_transactions')->where('id', $decision->transaction->id)->update(['voided_at' => now()]);
    DB::table('transport_transactions')->insert($row);
    expect(DB::table('transport_transactions')->where('transport_trip_id', $this->trip->id)->count())->toBe(2);
});

it('builds the partial unique indexes on PostgreSQL and takes the per-employee advisory lock', function (): void {
    $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename IN ('transport_transactions', 'transport_trips')"))->keyBy('indexname');
    expect($indexes)->toHaveKeys(['ttx_one_accepted_boarding_per_trip', 'tt_one_trip_per_schedule_date'])
        ->and($indexes['ttx_one_accepted_boarding_per_trip']->indexdef)->toContain('WHERE');

    DB::transaction(function (): void {
        app(BoardingLock::class)->forEmployee($this->employee->id);
        $held = DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()");
        expect((int) $held->n)->toBeGreaterThan(0);
    });
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL release gate (phpunit.pgsql.xml.dist)');
