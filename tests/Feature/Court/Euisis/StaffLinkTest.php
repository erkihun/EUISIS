<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\EuisisEmployee;
use Modules\Court\Domain\Models\EuisisOrganization;
use Modules\Court\Domain\Models\EuisisOrganizationUnit;
use Modules\Court\Domain\Models\EuisisPosition;
use Modules\Court\Domain\Models\EuisisStaffNotice;
use Modules\Court\Domain\Models\EuisisStaffUnit;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\Euisis\EuisisSync;
use Modules\Court\Application\Services\Euisis\StaffLink;
use Modules\Court\Application\Services\Notifications\AdminNotificationFeed;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Staff accounts linked to their EUISIS employees (docs/euisis-integration.md,
| "Staff accounts"): the court's units, linking by employee number, the roster,
| and suspension after `euisis:sync` when an employee leaves the court's units.
*/

const STAFF_ORG = '02b0deef-0000-7000-8000-000000000001';
const STAFF_COURT_UNIT = '02b0deef-0000-7000-8000-000000000002';
const STAFF_BENCH_UNIT = '02b0deef-0000-7000-8000-000000000003';
const STAFF_OTHER_UNIT = '02b0deef-0000-7000-8000-000000000004';
const STAFF_POSITION = '02b0deef-0000-7000-8000-000000000005';
const STAFF_EMPLOYEE = '02b0deef-0000-7000-8000-000000000006';
const STAFF_OTHER_EMPLOYEE = '02b0deef-0000-7000-8000-000000000007';

beforeEach(function (): void {
    config([
        'court.euisis.enabled' => true,
        'court.euisis.url' => 'https://euisis.test',
        'court.euisis.token' => 'partner-token',
    ]);

    EuisisOrganization::query()->create(['uuid' => STAFF_ORG, 'code' => 'AAJA', 'name_en' => 'Justice Bureau', 'name_am' => 'የፍትሕ ቢሮ', 'status' => 'active']);
    EuisisOrganizationUnit::query()->create(['uuid' => STAFF_COURT_UNIT, 'organization_uuid' => STAFF_ORG, 'parent_unit_uuid' => null, 'code' => 'ADMCRT', 'name_en' => 'Administrative Court', 'name_am' => 'የአስተዳደር ፍርድ ቤት', 'status' => 'active']);
    EuisisOrganizationUnit::query()->create(['uuid' => STAFF_BENCH_UNIT, 'organization_uuid' => STAFF_ORG, 'parent_unit_uuid' => STAFF_COURT_UNIT, 'code' => 'BENCH1', 'name_en' => 'First Bench', 'name_am' => null, 'status' => 'active']);
    EuisisOrganizationUnit::query()->create(['uuid' => STAFF_OTHER_UNIT, 'organization_uuid' => STAFF_ORG, 'parent_unit_uuid' => null, 'code' => 'FIN', 'name_en' => 'Finance', 'name_am' => null, 'status' => 'active']);
    EuisisPosition::query()->create(['uuid' => STAFF_POSITION, 'organization_uuid' => STAFF_ORG, 'unit_uuid' => STAFF_COURT_UNIT, 'code' => 'JDG', 'title_en' => 'Judge', 'title_am' => 'ዳኛ', 'is_active' => true]);
});

/** An EUISIS employee record as `/api/v1/employees` returns it. */
function staffEmployeeRow(array $overrides = []): array
{
    $unit = $overrides['unit'] ?? STAFF_COURT_UNIT;
    unset($overrides['unit']);

    return [
        'id' => STAFF_EMPLOYEE,
        'employee_number' => 'AA-1001',
        'full_name' => 'Selam Tesfaye Bekele',
        'full_name_en' => 'Selam Tesfaye Bekele',
        'employment_status' => 'active',
        'organization_id' => STAFF_ORG,
        'organization_unit_id' => $unit,
        'position_id' => STAFF_POSITION,
        'active_assignment' => ['id' => (string) Str::uuid(), 'employee_id' => $overrides['id'] ?? STAFF_EMPLOYEE, 'organization_id' => STAFF_ORG, 'organization_unit_id' => $unit, 'position_id' => STAFF_POSITION, 'is_current' => true],
        ...$overrides,
    ];
}

/**
 * Fakes the EUISIS employee directory with these records and change-feed page. The first
 * fake registered in a test keeps answering, so every call only swaps what it answers with.
 */
function staffFakeDirectory(array $employees, array $feed = []): void
{
    $GLOBALS['staffDirectory'] = ['employees' => $employees, 'feed' => $feed];
    Http::fake(function (HttpRequest $request) {
        ['employees' => $employees, 'feed' => $feed] = $GLOBALS['staffDirectory'];
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (str_contains($url, '/api/v1/changes')) {
            return Http::response(['data' => $feed, 'next_cursor' => 'W2', 'has_more' => false]);
        }
        if (preg_match('#/api/v1/employees/([0-9a-f-]{36})$#', (string) parse_url($url, PHP_URL_PATH), $match)) {
            $row = collect($employees)->firstWhere('id', $match[1]);

            return $row === null ? Http::response(['message' => 'Not Found.', 'error_code' => 'not_found'], 404) : Http::response(['data' => $row]);
        }
        if (str_contains($url, '/api/v1/employees')) {
            $rows = collect($employees)
                ->when(isset($query['employee_number']), fn ($c) => $c->where('employee_number', $query['employee_number']))
                ->when(isset($query['organization_unit_code']), fn ($c) => $c->filter(fn (array $row) => EuisisOrganizationUnit::query()->find($row['organization_unit_id'])?->code === $query['organization_unit_code']))
                ->values()->all();

            return Http::response(['data' => $rows, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($rows)]]);
        }

        return Http::response(['data' => [], 'meta' => ['last_page' => 1]]);
    });
}

function staffRole(string $name, array $permissions = []): Role
{
    $role = Role::query()->firstOrCreate(['name' => $name]);
    $role->permissions()->sync(collect($permissions)->map(
        fn (string $permission): int => Permission::query()->firstOrCreate(['name' => $permission], ['label' => Str::headline($permission)])->id,
    )->all());

    return $role;
}

function staffUser(?Role $role = null, array $attributes = []): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now(), 'password' => Hash::make('Secret-Password-1'), ...$attributes]);
    if ($role !== null) {
        $user->roles()->sync([$role->id]);
    }

    return $user;
}

function staffCourtUnit(bool $withSubUnits = true): void
{
    EuisisStaffUnit::query()->create(['unit_uuid' => STAFF_COURT_UNIT, 'organization_uuid' => STAFF_ORG, 'include_sub_units' => $withSubUnits]);
}

/** A user linked to the cached court employee, as a link from the screen leaves it. */
function staffLinkedUser(array $employee = []): User
{
    staffFakeDirectory([staffEmployeeRow($employee)]);
    $user = staffUser();
    app(StaffLink::class)->link($user, 'AA-1001');

    return $user->refresh();
}

function staffRunSync(): void
{
    $sync = app(EuisisSync::class);
    $sync->remember(EuisisSync::CURSOR_KEY, 'W1');
    $sync->remember(EuisisSync::BOOTSTRAPPED_KEY, now()->toIso8601String());
    test()->artisan('court:euisis-sync')->assertSuccessful();
}

function staffFeedEvent(string $type, int $sequence, array $data): array
{
    return ['event_id' => (string) Str::uuid(), 'event_type' => $type, 'event_version' => 1, 'aggregate_uuid' => STAFF_EMPLOYEE, 'sequence' => $sequence, 'change_kind' => 'upsert', 'data' => ['employee_uuid' => STAFF_EMPLOYEE, ...$data]];
}

test('a manager links a staff account by employee number and its page shows the employee from EUISIS, read-only', function (): void {
    staffCourtUnit();
    staffFakeDirectory([staffEmployeeRow()]);
    $admin = staffUser(staffRole('admin'));
    $clerk = staffUser();

    $this->actingAs($admin)->post(route('court.users.euisis.link', $clerk), ['employee_number' => 'AA-1001'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $clerk->refresh();
    expect($clerk->euisis_employee_uuid)->toBe(STAFF_EMPLOYEE)
        ->and($clerk->euisis_linked_at)->not->toBeNull()
        ->and(EuisisEmployee::query()->find(STAFF_EMPLOYEE)?->employee_number)->toBe('AA-1001');
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'employee_number=AA-1001'));

    $this->actingAs($admin)->get(route('court.users.show', $clerk))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Court/Users/Show')
            ->where('euisis.linked', true)
            ->where('euisis.employee.employee_number', 'AA-1001')
            ->where('euisis.employee.name', 'Selam Tesfaye Bekele')
            ->where('euisis.employee.position', 'Judge')
            ->where('euisis.employee.unit', 'Administrative Court')
            ->where('euisis.employee.organization', 'Justice Bureau')
            ->where('euisis.employee.in_court', true)
            ->where('can.linkEuisis', true));
});

test('only an active employee placed in the court units, and not linked elsewhere, can be linked', function (string $case, string $reason): void {
    $admin = staffUser(staffRole('admin'));
    $clerk = staffUser();
    if ($case !== 'no_units') {
        staffCourtUnit($case !== 'sub_unit_excluded');
    }
    staffFakeDirectory([match ($case) {
        'other_unit' => staffEmployeeRow(['unit' => STAFF_OTHER_UNIT]),
        'sub_unit_excluded' => staffEmployeeRow(['unit' => STAFF_BENCH_UNIT]),
        'retired' => staffEmployeeRow(['employment_status' => 'retired']),
        default => staffEmployeeRow(),
    }]);
    if ($case === 'taken') {
        staffUser(null, ['email' => 'judge@example.test'])->forceFill(['euisis_employee_uuid' => STAFF_EMPLOYEE])->save();
    }

    $this->actingAs($admin)->from(route('court.users.show', $clerk))
        ->post(route('court.users.euisis.link', $clerk), ['employee_number' => $case === 'unknown' ? 'AA-9999' : 'AA-1001'])
        ->assertRedirect(route('court.users.show', $clerk))
        ->assertSessionHasErrors(['employee_number' => __('court::euisis_staff.errors.'.$reason)]);

    expect($clerk->refresh()->euisis_employee_uuid)->toBeNull();
})->with([
    'no court unit chosen' => ['no_units', 'no_court_unit'],
    'unknown number' => ['unknown', 'not_found'],
    'another unit' => ['other_unit', 'outside_court'],
    'a sub-unit while sub-units are left out' => ['sub_unit_excluded', 'outside_court'],
    'no longer active' => ['retired', 'not_active'],
    'linked to another account' => ['taken', 'linked_to_another_account'],
]);

test('an employee of a sub-unit is court staff when the court unit includes its sub-units', function (): void {
    staffCourtUnit(true);
    $user = staffLinkedUser(['unit' => STAFF_BENCH_UNIT]);

    expect($user->euisis_employee_uuid)->toBe(STAFF_EMPLOYEE)
        ->and(app(StaffLink::class)->unitUuids())->toEqualCanonicalizing([STAFF_COURT_UNIT, STAFF_BENCH_UNIT]);
});

test('the roster lists the court units\' employees from EUISIS, marks who has an account, and Create account links the new one', function (): void {
    staffCourtUnit();
    $other = staffEmployeeRow(['id' => STAFF_OTHER_EMPLOYEE, 'employee_number' => 'AA-1002', 'full_name' => 'Dawit Alemu Girma', 'full_name_en' => 'Dawit Alemu Girma', 'unit' => STAFF_BENCH_UNIT]);
    $linked = staffLinkedUser();
    staffFakeDirectory([staffEmployeeRow(), $other]);
    $admin = staffUser(staffRole('admin'));

    $this->actingAs($admin)->get(route('court.users.euisis.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Court/Users/EuisisStaff')
            ->has('roster.employees', 2)
            ->where('roster.employees.0.employee_number', 'AA-1001')
            ->where('roster.employees.0.account.id', $linked->id)
            ->where('roster.employees.0.position', 'Judge')
            ->where('roster.employees.1.employee_number', 'AA-1002')
            ->where('roster.employees.1.unit', 'First Bench')
            ->where('roster.employees.1.account', null)
            ->where('units.0.unit_uuid', STAFF_COURT_UNIT)
            ->where('canManageUnits', true));
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'organization_code=AAJA') && str_contains($request->url(), 'organization_unit_code=BENCH1'));

    $this->actingAs($admin)->get(route('court.users.create', ['euisis_employee' => STAFF_OTHER_EMPLOYEE]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Court/Users/Create')
            ->where('euisisPrefill.employee_number', 'AA-1002')
            ->where('euisisPrefill.first_name', 'Dawit')
            ->where('euisisPrefill.middle_name', 'Alemu')
            ->where('euisisPrefill.last_name', 'Girma'));

    $registrar = staffRole('registrar');
    $this->actingAs($admin)->post(route('court.users.store'), [
        'first_name' => 'Dawit', 'middle_name' => 'Alemu', 'last_name' => 'Girma',
        'email' => 'dawit@example.test', 'status' => 'active', 'roles' => [$registrar->id],
        'euisis_employee_number' => 'AA-1002',
    ])->assertRedirect(route('court.users.index'))->assertSessionHasNoErrors();

    $created = User::query()->where('email', 'dawit@example.test')->firstOrFail();
    expect($created->euisis_employee_uuid)->toBe(STAFF_OTHER_EMPLOYEE)
        ->and($created->roles()->pluck('name')->all())->toBe(['registrar']);
});

test('an account for an employee outside the court units is not created', function (): void {
    staffCourtUnit();
    staffFakeDirectory([staffEmployeeRow(['unit' => STAFF_OTHER_UNIT])]);
    $admin = staffUser(staffRole('admin'));

    $this->actingAs($admin)->post(route('court.users.store'), [
        'first_name' => 'Selam', 'last_name' => 'Bekele', 'email' => 'selam@example.test', 'status' => 'active',
        'euisis_employee_number' => 'AA-1001',
    ])->assertSessionHasErrors(['euisis_employee_number' => __('court::euisis_staff.errors.outside_court')]);

    expect(User::query()->where('email', 'selam@example.test')->exists())->toBeFalse();
});

test('choosing the court units needs the system settings permission as well', function (): void {
    $manager = staffUser(staffRole('user manager', ['users.manage']));
    $settings = staffUser(staffRole('settings manager', ['users.manage', 'settings.manage']));
    $payload = ['units' => [['unit_uuid' => STAFF_COURT_UNIT, 'include_sub_units' => false]]];

    $this->actingAs($manager)->get(route('court.users.euisis.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManageUnits', false));
    $this->actingAs($manager)->put(route('court.users.euisis.units'), $payload)->assertForbidden();
    expect(EuisisStaffUnit::query()->count())->toBe(0);

    $this->actingAs($settings)->put(route('court.users.euisis.units'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($settings)->put(route('court.users.euisis.units'), ['units' => [['unit_uuid' => (string) Str::uuid(), 'include_sub_units' => true]]])
        ->assertSessionHasErrors('units.0.unit_uuid');

    $unit = EuisisStaffUnit::query()->sole();
    expect($unit->unit_uuid)->toBe(STAFF_COURT_UNIT)
        ->and($unit->organization_uuid)->toBe(STAFF_ORG)
        ->and($unit->include_sub_units)->toBeFalse();
});

test('a linked employee who leaves the court unit is suspended at the next sync, the admins are told, and the account is lifted when they come back', function (): void {
    staffCourtUnit();
    $user = staffLinkedUser();
    $user->createToken('mobile');
    $admin = staffUser(staffRole('admin'));
    $assignment = (string) Str::uuid();

    staffFakeDirectory([staffEmployeeRow()], [
        staffFeedEvent('employee.transferred', 5, ['organization_uuid' => STAFF_ORG, 'unit_uuid' => STAFF_OTHER_UNIT, 'position_uuid' => STAFF_POSITION, 'assignment_uuid' => $assignment]),
    ]);
    staffRunSync();

    $user->refresh();
    expect($user->exists)->toBeTrue()
        ->and($user->status)->toBe('inactive')
        ->and($user->euisis_suspended_at)->not->toBeNull()
        ->and($user->euisis_suspension_reason)->toBe(StaffLink::LEFT_UNIT)
        ->and($user->euisis_employee_uuid)->toBe(STAFF_EMPLOYEE)
        ->and($user->tokens()->count())->toBe(0)
        ->and(EuisisStaffNotice::query()->where('user_id', $user->id)->pluck('event')->all())->toBe(['suspended']);

    $bell = (new AdminNotificationFeed($admin))->feed(1);
    expect(collect($bell['items'])->pluck('title')->all())->toContain(__('court::euisis_staff.notice.suspended', ['name' => $user->name]));

    // The suspended account cannot sign in.
    $this->post(route('login'), ['email' => $user->email, 'password' => 'Secret-Password-1'])
        ->assertSessionHasErrors(['email' => __('court::euisis_staff.sign_in_suspended')]);
    $this->assertGuest('web');

    // A second run changes nothing: one notice, still suspended.
    staffFakeDirectory([staffEmployeeRow()]);
    staffRunSync();
    expect(EuisisStaffNotice::query()->count())->toBe(1);

    staffFakeDirectory([staffEmployeeRow()], [
        staffFeedEvent('employee.transferred', 6, ['organization_uuid' => STAFF_ORG, 'unit_uuid' => STAFF_BENCH_UNIT, 'position_uuid' => STAFF_POSITION, 'assignment_uuid' => $assignment]),
    ]);
    staffRunSync();

    $user->refresh();
    expect($user->status)->toBe('active')
        ->and($user->euisis_suspended_at)->toBeNull()
        ->and($user->euisis_suspension_reason)->toBeNull()
        ->and(EuisisStaffNotice::query()->where('user_id', $user->id)->orderBy('id')->pluck('event')->all())->toBe(['suspended', 'restored']);
});

test('a linked employee who is no longer active in EUISIS is suspended for that reason', function (): void {
    staffCourtUnit();
    $user = staffLinkedUser();
    staffFakeDirectory([staffEmployeeRow()], [staffFeedEvent('employee.status_changed', 7, ['status' => 'terminated'])]);

    staffRunSync();

    expect($user->refresh()->status)->toBe('inactive')
        ->and($user->euisis_suspension_reason)->toBe(StaffLink::NOT_ACTIVE);
});

test('without the change feed, linked employees are read again and one EUISIS no longer has is suspended', function (): void {
    staffCourtUnit();
    $user = staffLinkedUser();
    EuisisEmployee::query()->whereKey(STAFF_EMPLOYEE)->update(['synced_at' => now()->subHours(2)]);
    staffFakeDirectory([]); // EUISIS answers 404 for the employee

    $summary = app(StaffLink::class)->review(refresh: true);

    expect($summary)->toMatchArray(['checked' => 1, 'suspended' => 1])
        ->and($user->refresh()->euisis_suspension_reason)->toBe(StaffLink::NOT_IN_EUISIS);
});

test('with no court unit chosen the check suspends nobody', function (): void {
    staffCourtUnit();
    $user = staffLinkedUser();
    EuisisStaffUnit::query()->delete();
    EuisisEmployee::query()->whereKey(STAFF_EMPLOYEE)->update(['unit_uuid' => STAFF_OTHER_UNIT]);

    expect(app(StaffLink::class)->review())->toMatchArray(['checked' => 0, 'suspended' => 0])
        ->and($user->refresh()->status)->toBe('active');
});


test('a manager setting a suspended account active lifts the suspension; unlinking keeps it', function (): void {
    staffCourtUnit();
    $admin = staffUser(staffRole('admin'));
    $user = staffLinkedUser();
    app(StaffLink::class)->suspend($user, StaffLink::LEFT_UNIT);

    $this->actingAs($admin)->delete(route('court.users.euisis.unlink', $user))->assertRedirect();
    $user->refresh();
    expect($user->euisis_employee_uuid)->toBeNull()
        ->and($user->euisis_suspended_at)->not->toBeNull()
        ->and($user->status)->toBe('inactive');

    [$first, $last] = explode(' ', (string) $user->name, 2) + [1 => 'Person'];
    $this->actingAs($admin)->patch(route('court.users.update', $user), [
        'first_name' => $first, 'middle_name' => '', 'last_name' => $last, 'email' => $user->email, 'status' => 'active',
        'gender' => '', 'date_of_birth' => '', 'national_id' => '', 'position' => '', 'phone' => '', 'address' => '', 'remove_avatar' => 0,
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->status)->toBe('active')
        ->and($user->euisis_suspended_at)->toBeNull();
});
