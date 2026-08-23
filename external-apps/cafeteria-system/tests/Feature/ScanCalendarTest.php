<?php

declare(strict_types=1);

use CafeteriaSystem\Models\Cafeteria;
use CafeteriaSystem\Models\CafeteriaOrganizationAssignment;
use CafeteriaSystem\Models\CafeteriaProvider;
use CafeteriaSystem\Models\CafeteriaPublicHoliday;
use CafeteriaSystem\Models\CafeteriaServiceTransaction;
use CafeteriaSystem\Models\CafeteriaSetting;
use CafeteriaSystem\Models\CafeteriaSpecialDay;
use CafeteriaSystem\Models\CafeteriaUser;
use CafeteriaSystem\Services\CafeteriaCalendarService;
use CafeteriaSystem\Services\ServeEmployeeService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('euisis.base_url', 'https://euisis.test');
    config()->set('euisis.token', 'test-token');

    $this->provider = CafeteriaProvider::query()->create([
        'code' => 'CAF-CAL',
        'name' => 'Calendar Cafe',
        'status' => 'active',
    ]);

    $this->cafeteria = Cafeteria::query()->create([
        'provider_id' => $this->provider->id,
        'name' => 'Calendar Point',
        'code' => 'CAL-TP',
        'status' => 'active',
    ]);

    CafeteriaOrganizationAssignment::query()->create([
        'cafeteria_id' => $this->cafeteria->id,
        'organization_code' => 'ORG-1',
        'organization_name_snapshot' => 'Bole Sub-city',
        'status' => 'active',
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    $this->operator = CafeteriaUser::query()->create([
        'provider_id' => $this->provider->id,
        'cafeteria_id' => $this->cafeteria->id,
        'name' => 'Calendar Operator',
        'email' => 'calendar@test.local',
        'password' => 'password',
        'role' => 'operator',
        'status' => 'active',
    ]);

    $this->calendar = app(CafeteriaCalendarService::class);
});

/** Day metadata for one ISO date. */
function dayFor(string $iso, array $days): ?array
{
    foreach ($days as $day) {
        if ($day['date'] === $iso) {
            return $day;
        }
    }

    return null;
}

it('marks an ordinary weekday as available', function (): void {
    $monday = now()->startOfWeek();

    $days = $this->calendar->days($this->provider->id, $this->cafeteria->id, $monday, $monday);

    expect(dayFor($monday->toDateString(), $days))
        ->not->toBeNull()
        ->and(dayFor($monday->toDateString(), $days)['reason_code'])->toBe('available')
        ->and(dayFor($monday->toDateString(), $days)['is_available'])->toBeTrue();
});

it('marks a weekend as closed by default', function (): void {
    $saturday = now()->startOfWeek()->addDays(5);

    $day = dayFor(
        $saturday->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $saturday, $saturday),
    );

    expect($day['reason_code'])->toBe('closed')
        ->and($day['is_open'])->toBeFalse()
        ->and($day['is_available'])->toBeFalse();
});

it('marks a configured public holiday', function (): void {
    $target = now()->startOfWeek()->addDay();

    CafeteriaPublicHoliday::query()->create([
        'provider_id' => $this->provider->id,
        'holiday_date' => $target->toDateString(),
        'name_en' => 'Test Holiday',
        'is_active' => true,
    ]);

    $day = dayFor(
        $target->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $target, $target),
    );

    expect($day['is_public_holiday'])->toBeTrue()
        ->and($day['reason_code'])->toBe('public_holiday')
        ->and($day['is_available'])->toBeFalse();
});

it('opens a special open day that falls on a weekend', function (): void {
    $saturday = now()->startOfWeek()->addDays(5);

    CafeteriaSpecialDay::query()->create([
        'provider_id' => $this->provider->id,
        'special_date' => $saturday->toDateString(),
        'name_en' => 'Working Saturday',
        'day_type' => CafeteriaSpecialDay::TYPE_OPEN_DAY,
        'is_open' => true,
        'is_subsidy_day' => true,
        'is_active' => true,
    ]);

    $day = dayFor(
        $saturday->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $saturday, $saturday),
    );

    // The override must beat the weekend rule, or a working Saturday is unusable.
    expect($day['reason_code'])->toBe('special_open_day')
        ->and($day['is_open'])->toBeTrue()
        ->and($day['is_available'])->toBeTrue();
});

it('marks a special day that withdraws the subsidy', function (): void {
    $target = now()->startOfWeek()->addDay();

    CafeteriaSpecialDay::query()->create([
        'provider_id' => $this->provider->id,
        'special_date' => $target->toDateString(),
        'name_en' => 'No Subsidy Day',
        'day_type' => CafeteriaSpecialDay::TYPE_NO_SUBSIDY,
        'is_open' => true,
        'is_subsidy_day' => false,
        'is_active' => true,
    ]);

    $day = dayFor(
        $target->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $target, $target),
    );

    expect($day['reason_code'])->toBe('special_no_subsidy_day')
        ->and($day['is_available'])->toBeFalse();
});

it('marks employee leave supplied by the caller', function (): void {
    $target = now()->startOfWeek()->addDay();

    $day = dayFor(
        $target->toDateString(),
        $this->calendar->days(
            $this->provider->id,
            $this->cafeteria->id,
            $target,
            $target,
            [],
            [$target->toDateString()],
        ),
    );

    expect($day['is_employee_excluded'])->toBeTrue()
        ->and($day['reason_code'])->toBe('employee_on_leave')
        ->and($day['is_available'])->toBeFalse();
});

it('marks a day already served as consumed', function (): void {
    $target = now()->startOfWeek()->addDay();

    CafeteriaServiceTransaction::query()->create([
        'transaction_number' => 'CAF-CAL-1',
        'provider_id' => $this->provider->id,
        'cafeteria_id' => $this->cafeteria->id,
        'organization_code' => 'ORG-1',
        'employee_number' => 'EMP-1',
        'status' => 'served',
        'service_type' => 'meal',
        'service_date' => $target->toDateString(),
        'served_at' => $target,
    ]);

    $day = dayFor(
        $target->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $target, $target),
    );

    // Consumed outranks available, or a served day would invite a second scan.
    expect($day['is_consumed'])->toBeTrue()
        ->and($day['reason_code'])->toBe('consumed')
        ->and($day['is_available'])->toBeFalse();
});

it('ignores a holiday belonging to another provider', function (): void {
    $other = CafeteriaProvider::query()->create([
        'code' => 'CAF-OTHER',
        'name' => 'Other Provider',
        'status' => 'active',
    ]);

    $target = now()->startOfWeek()->addDay();

    CafeteriaPublicHoliday::query()->create([
        'provider_id' => $other->id,
        'holiday_date' => $target->toDateString(),
        'name_en' => 'Their Holiday',
        'is_active' => true,
    ]);

    $day = dayFor(
        $target->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $target, $target),
    );

    // Provider data isolation: one provider's calendar must not leak into another's.
    expect($day['is_public_holiday'])->toBeFalse()
        ->and($day['is_available'])->toBeTrue();
});

it('applies a national holiday to every provider', function (): void {
    $target = now()->startOfWeek()->addDay();

    CafeteriaPublicHoliday::query()->create([
        'provider_id' => null,
        'holiday_date' => $target->toDateString(),
        'name_en' => 'National Day',
        'is_active' => true,
    ]);

    $day = dayFor(
        $target->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $target, $target),
    );

    expect($day['is_public_holiday'])->toBeTrue();
});

it('ignores an inactive holiday', function (): void {
    $target = now()->startOfWeek()->addDay();

    CafeteriaPublicHoliday::query()->create([
        'provider_id' => $this->provider->id,
        'holiday_date' => $target->toDateString(),
        'name_en' => 'Cancelled Holiday',
        'is_active' => false,
    ]);

    $day = dayFor(
        $target->toDateString(),
        $this->calendar->days($this->provider->id, $this->cafeteria->id, $target, $target),
    );

    expect($day['is_public_holiday'])->toBeFalse();
});

it('passes calendar days and organizations to the scan page', function (): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Scan/Index')
            ->has('calendar_days')
            ->has('organizations', 1)
            ->where('organizations.0.code', 'ORG-1')
            ->where('default_usage_mode', 'single_day')
        );
});

it('records the usage mode chosen at scan time', function (): void {
    // Pinned to a working day: the serve path now refuses closed days.
    $this->travelTo(now()->startOfWeek()->addDay());

    Http::fake([
        '*/api/v1/id-cards/verify/*' => Http::response([
            'valid' => true,
            'status' => 'active',
            'employee' => ['employee_number' => 'EMP-9', 'full_name' => 'Test Person', 'status' => 'active'],
            'organization' => ['code' => 'ORG-1', 'name_en' => 'Bole Sub-city'],
        ]),
        '*/api/v1/employees/*' => Http::response(['eligible' => true]),
    ]);

    $this->actingAs($this->operator, 'cafeteria')
        ->postJson('/scan/verify', [
            'card_token' => '11111111-2222-3333-4444-555555555555',
            'usage_mode' => 'use_remaining_week',
        ])
        ->assertOk();

    expect(CafeteriaServiceTransaction::query()->where('employee_number', 'EMP-9')->sole()->usage_mode)
        ->toBe('use_remaining_week');
});

it('rejects an unknown usage mode', function (): void {
    $this->actingAs($this->operator, 'cafeteria')
        ->postJson('/scan/verify', [
            'card_token' => '11111111-2222-3333-4444-555555555555',
            'usage_mode' => 'unlimited_forever',
        ])
        ->assertStatus(422);
});

it('falls back to the provider default usage mode', function (): void {
    CafeteriaSetting::query()->create([
        'provider_id' => $this->provider->id,
        'key' => 'default_usage_mode',
        'value' => 'use_remaining_week',
        'type' => 'string',
        'group' => 'scan',
    ]);

    $this->actingAs($this->operator, 'cafeteria')
        ->get('/scan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('default_usage_mode', 'use_remaining_week'));
});

it('passes the employee photo through to the operator without storing it', function (): void {
    $this->travelTo(now()->startOfWeek()->addDay());

    Http::fake([
        '*/api/v1/id-cards/verify/*' => Http::response([
            'valid' => true,
            'status' => 'active',
            'employee' => [
                'employee_number' => 'EMP-PHOTO',
                'full_name' => 'Photo Person',
                'status' => 'active',
                'photo_url' => 'https://euisis.test/storage/employees/photos/a/b.jpg',
            ],
            'organization' => ['code' => 'ORG-1', 'name_en' => 'Bole Sub-city'],
        ]),
        '*/api/v1/employees/*' => Http::response(['eligible' => true]),
    ]);

    $response = $this->actingAs($this->operator, 'cafeteria')
        ->postJson('/scan/verify', ['card_token' => '11111111-2222-3333-4444-555555555555'])
        ->assertOk();

    // Shown to the operator...
    expect($response->json('employee.photo_url'))
        ->toBe('https://euisis.test/storage/employees/photos/a/b.jpg');

    // ...but never written to the cafeteria database.
    $stored = CafeteriaServiceTransaction::query()->where('employee_number', 'EMP-PHOTO')->sole();

    expect($stored->getAttributes())->not->toHaveKey('photo_url')
        ->and(json_encode($stored->getAttributes()))->not->toContain('photos/a/b.jpg');
});

// ── Serve must respect the working-day calendar ────────────────────────

/** Fake a successful EUISIS verify + eligibility for an assigned organization. */
function fakeEuisisOk(): void
{
    Http::fake([
        '*/api/v1/id-cards/verify/*' => Http::response([
            'valid' => true,
            'status' => 'active',
            'employee' => ['employee_number' => 'EMP-DAY', 'full_name' => 'Day Person', 'status' => 'active'],
            'organization' => ['code' => 'ORG-1', 'name_en' => 'Bole Sub-city'],
        ]),
        '*/api/v1/employees/*' => Http::response(['eligible' => true]),
    ]);
}

it('refuses to serve on a closed weekend', function (): void {
    // Saturday, with the default weekend-closed rule in force.
    $this->travelTo(now()->startOfWeek()->addDays(5));
    fakeEuisisOk();

    $result = app(ServeEmployeeService::class)
        ->serve('11111111-2222-3333-4444-555555555555', $this->cafeteria, $this->operator->id);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('closed_today')
        ->and(CafeteriaServiceTransaction::query()->count())->toBe(0);
});

it('serves on an ordinary working day', function (): void {
    $this->travelTo(now()->startOfWeek()->addDay());
    fakeEuisisOk();

    $result = app(ServeEmployeeService::class)
        ->serve('11111111-2222-3333-4444-555555555555', $this->cafeteria, $this->operator->id);

    expect($result['served'])->toBeTrue();
});

it('serves on a weekend that is a configured special open day', function (): void {
    $saturday = now()->startOfWeek()->addDays(5);

    CafeteriaSpecialDay::query()->create([
        'provider_id' => $this->provider->id,
        'special_date' => $saturday->toDateString(),
        'name_en' => 'Working Saturday',
        'day_type' => CafeteriaSpecialDay::TYPE_OPEN_DAY,
        'is_open' => true,
        'is_subsidy_day' => true,
        'is_active' => true,
    ]);

    $this->travelTo($saturday);
    fakeEuisisOk();

    // The override must win, or a declared working Saturday cannot be served.
    expect(app(ServeEmployeeService::class)
        ->serve('11111111-2222-3333-4444-555555555555', $this->cafeteria, $this->operator->id)['served'])
        ->toBeTrue();
});

it('refuses to serve on a public holiday', function (): void {
    $monday = now()->startOfWeek();

    CafeteriaPublicHoliday::query()->create([
        'provider_id' => $this->provider->id,
        'holiday_date' => $monday->toDateString(),
        'name_en' => 'Test Holiday',
        'is_active' => true,
    ]);

    $this->travelTo($monday);
    fakeEuisisOk();

    $result = app(ServeEmployeeService::class)
        ->serve('11111111-2222-3333-4444-555555555555', $this->cafeteria, $this->operator->id);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('closed_public_holiday');
});

it('refuses to serve on a no-subsidy special day', function (): void {
    $monday = now()->startOfWeek();

    CafeteriaSpecialDay::query()->create([
        'provider_id' => $this->provider->id,
        'special_date' => $monday->toDateString(),
        'name_en' => 'No Subsidy Day',
        'day_type' => CafeteriaSpecialDay::TYPE_NO_SUBSIDY,
        'is_open' => true,
        'is_subsidy_day' => false,
        'is_active' => true,
    ]);

    $this->travelTo($monday);
    fakeEuisisOk();

    $result = app(ServeEmployeeService::class)
        ->serve('11111111-2222-3333-4444-555555555555', $this->cafeteria, $this->operator->id);

    expect($result['served'])->toBeFalse()
        ->and($result['result_code'])->toBe('no_subsidy_today');
});

it('serves on a weekend when the provider allows weekend service', function (): void {
    CafeteriaSetting::query()->create([
        'provider_id' => $this->provider->id,
        'key' => 'closed_weekend_default',
        'value' => '0',
        'type' => 'boolean',
        'group' => 'days',
    ]);

    $this->travelTo(now()->startOfWeek()->addDays(5));
    fakeEuisisOk();

    // The setting must actually reach the serve path, not just the calendar UI.
    expect(app(ServeEmployeeService::class)
        ->serve('11111111-2222-3333-4444-555555555555', $this->cafeteria, $this->operator->id)['served'])
        ->toBeTrue();
});
