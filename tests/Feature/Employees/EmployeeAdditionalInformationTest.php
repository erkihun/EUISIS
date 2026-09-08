<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\OrganizationScopeType;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PublicIdCheckOtp;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\Api\OrganizationDataPresenter;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\PublicIdCheckerService;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Additional Employee Information — address, nationality and the emergency
 * contact, plus employment status (which is the existing `employees.status`
 * column, not a second field).
 *
 * The privacy tests matter as much as the CRUD ones: these fields are visible
 * to an in-scope, authenticated administrator and to nobody else. The public ID
 * Checker must keep returning its own short field list even after an employee
 * has an address and a next-of-kin on file.
 */
beforeEach(function (): void {
    foreach (['employees.view', 'employees.viewAny', 'employees.manage'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('AI Manager', 'web')->givePermissionTo(['employees.view', 'employees.viewAny', 'employees.manage']);

    $this->aiType = OrganizationType::query()->create([
        'code' => 'AI-TYPE',
        'name_en' => 'Additional Info Test Type',
    ]);

    CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::Employee->value,
        'scope_type' => null,
        'scope_id' => null,
        'name_en' => 'Employee Number',
        'prefix' => 'AIE',
        'format' => '{PREFIX}-{SEQUENCE}',
        'separator' => '-',
        'sequence_length' => 6,
        'next_number' => 1,
        'initial_sequence_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Auto,
        'sequence_scope_tokens' => [],
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => true,
        'require_approval_for_override' => false,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::Employee),
    ]);
});

function aiManager(): User
{
    return tap(User::factory()->create())->assignRole('AI Manager');
}

function aiOrg(string $code): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->aiType->id,
        'code' => $code,
        'name_en' => 'Org '.$code,
        'status' => 'active',
        'effective_from' => now()->toDateString(),
    ]);
}

/** An employee already placed in $org, so the update route has a scope to check. */
function aiEmployeeIn(Organization $org, array $overrides = []): Employee
{
    $employee = Employee::query()->create(array_merge([
        'employee_number' => 'AI-EMP-'.uniqid(),
        'first_name' => 'Meron',
        'last_name' => 'Alemu',
        'full_name' => 'Meron Alemu',
        'status' => EmployeeStatus::Active->value,
    ], $overrides));

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return $employee->fresh();
}

/** The additional fields as a create/update payload fragment. */
function aiFields(array $overrides = []): array
{
    return array_merge([
        'address' => 'Bole Sub-city, Woreda 03, House 214',
        'nationality' => 'Ethiopian',
        'emergency_contact_name' => 'Alemu Bekele',
        'emergency_contact_phone' => '+251 911 222 333',
    ], $overrides);
}

// ── 1. Create form displays the new fields ────────────────────────────────

it('renders the create form with the additional information fields', function (): void {
    aiOrg('AI-1');

    $this->actingAs(aiManager())
        ->get(route('employees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employees/Create'));
});

// ── 2. Create with the new fields ─────────────────────────────────────────

it('creates an employee with address, nationality and emergency contact', function (): void {
    $org = aiOrg('AI-2');

    $this->actingAs(aiManager())
        ->post(route('employees.store'), array_merge([
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $org->id,
            'effective_from' => now()->toDateString(),
        ], aiFields()))
        ->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Abebe Bekele')->firstOrFail();

    expect($employee->address)->toBe('Bole Sub-city, Woreda 03, House 214')
        ->and($employee->nationality)->toBe('Ethiopian')
        ->and($employee->emergency_contact_name)->toBe('Alemu Bekele')
        ->and($employee->emergency_contact_phone)->toBe('+251 911 222 333')
        // Employment status is the existing status column.
        ->and($employee->status)->toBe(EmployeeStatus::Active);
});

it('creates an employee when the additional fields are omitted entirely', function (): void {
    $org = aiOrg('AI-2B');

    $this->actingAs(aiManager())
        ->post(route('employees.store'), [
            'first_name' => 'Sara',
            'last_name' => 'Girma',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $org->id,
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Sara Girma')->firstOrFail();

    expect($employee->address)->toBeNull()
        ->and($employee->nationality)->toBeNull()
        ->and($employee->emergency_contact_name)->toBeNull()
        ->and($employee->emergency_contact_phone)->toBeNull();
});

// ── 3. Update with the new fields ─────────────────────────────────────────

it('updates an employee with the additional information fields', function (): void {
    $org = aiOrg('AI-3');
    $employee = aiEmployeeIn($org);

    $this->actingAs(aiManager())
        ->patch(route('employees.update', $employee), array_merge([
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Suspended->value,
        ], aiFields(['nationality' => 'Kenyan'])))
        ->assertRedirect();

    $employee->refresh();

    expect($employee->address)->toBe('Bole Sub-city, Woreda 03, House 214')
        ->and($employee->nationality)->toBe('Kenyan')
        ->and($employee->emergency_contact_name)->toBe('Alemu Bekele')
        ->and($employee->emergency_contact_phone)->toBe('+251 911 222 333')
        ->and($employee->status)->toBe(EmployeeStatus::Suspended);
});

it('leaves existing employee data untouched when the new fields are blank', function (): void {
    $org = aiOrg('AI-3B');
    $employee = aiEmployeeIn($org, ['phone' => '0911000111', 'email' => 'meron@example.test']);

    $this->actingAs(aiManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Active->value,
        ])
        ->assertRedirect();

    $employee->refresh();

    expect($employee->phone)->toBe('0911000111')
        ->and($employee->email)->toBe('meron@example.test')
        ->and($employee->full_name)->toBe('Meron Alemu');
});

// ── 4. Detail page shows the new fields ───────────────────────────────────

it('shows the additional information on the employee detail page', function (): void {
    $org = aiOrg('AI-4');
    $employee = aiEmployeeIn($org, [
        'address' => 'Kirkos Sub-city, Woreda 08',
        'nationality' => 'Ethiopian',
        'emergency_contact_name' => 'Hana Tesfaye',
        'emergency_contact_phone' => '0911444555',
    ]);

    $this->actingAs(aiManager())
        ->get(route('employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Show')
            ->where('employee.address', 'Kirkos Sub-city, Woreda 08')
            ->where('employee.nationality', 'Ethiopian')
            ->where('employee.emergency_contact_name', 'Hana Tesfaye')
            ->where('employee.emergency_contact_phone', '0911444555')
        );
});

it('prefills the edit form with the stored additional information', function (): void {
    $org = aiOrg('AI-4B');
    $employee = aiEmployeeIn($org, [
        'address' => 'Yeka Sub-city',
        'nationality' => 'Ethiopian',
        'emergency_contact_name' => 'Dawit Haile',
        'emergency_contact_phone' => '0911666777',
    ]);

    $this->actingAs(aiManager())
        ->get(route('employees.edit', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employees/Edit')
            ->where('employee.address', 'Yeka Sub-city')
            ->where('employee.emergency_contact_phone', '0911666777')
        );
});

// ── 5. Validation ─────────────────────────────────────────────────────────

it('rejects an emergency contact phone containing letters', function (): void {
    $org = aiOrg('AI-5');

    $this->actingAs(aiManager())
        ->post(route('employees.store'), array_merge([
            'first_name' => 'Abebe',
            'last_name' => 'Bekele',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $org->id,
            'effective_from' => now()->toDateString(),
        ], aiFields(['emergency_contact_phone' => 'call my brother'])))
        ->assertSessionHasErrors('emergency_contact_phone');

    expect(Employee::query()->count())->toBe(0);
});

it('rejects an emergency contact phone longer than 50 characters', function (): void {
    $org = aiOrg('AI-5B');
    $employee = aiEmployeeIn($org);

    $this->actingAs(aiManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Active->value,
            'emergency_contact_phone' => str_repeat('1', 51),
        ])
        ->assertSessionHasErrors('emergency_contact_phone');

    expect($employee->fresh()->emergency_contact_phone)->toBeNull();
});

it('rejects an over-long address and nationality', function (): void {
    $org = aiOrg('AI-5C');
    $employee = aiEmployeeIn($org);

    $this->actingAs(aiManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Active->value,
            'address' => str_repeat('a', 1001),
            'nationality' => str_repeat('b', 101),
        ])
        ->assertSessionHasErrors(['address', 'nationality']);
});

it('accepts a plain local phone number for the emergency contact', function (): void {
    $org = aiOrg('AI-5D');
    $employee = aiEmployeeIn($org);

    $this->actingAs(aiManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Active->value,
            'emergency_contact_phone' => '(011) 551-2233',
        ])
        ->assertSessionHasNoErrors();

    expect($employee->fresh()->emergency_contact_phone)->toBe('(011) 551-2233');
});

it('rejects an employment status outside the EmployeeStatus enum', function (): void {
    $org = aiOrg('AI-5E');
    $employee = aiEmployeeIn($org);

    $this->actingAs(aiManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => 'on-sabbatical',
        ])
        ->assertSessionHasErrors('status');
});

// ── 6. Organization scope ─────────────────────────────────────────────────

it('forbids a scoped user from updating an employee outside their scope', function (): void {
    $ownOrg = aiOrg('AI-6-OWN');
    $otherOrg = aiOrg('AI-6-OTHER');

    $scopedUser = aiManager();
    UserOrganizationScope::query()->create([
        'user_id' => $scopedUser->id,
        'organization_id' => $ownOrg->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    $outsider = aiEmployeeIn($otherOrg);

    $this->actingAs($scopedUser->fresh())
        ->patch(route('employees.update', $outsider), array_merge([
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Active->value,
        ], aiFields()))
        ->assertForbidden();

    expect($outsider->fresh()->emergency_contact_name)->toBeNull()
        ->and($outsider->fresh()->address)->toBeNull();
});

it('lets a scoped user update an employee inside their scope', function (): void {
    $ownOrg = aiOrg('AI-6B-OWN');

    $scopedUser = aiManager();
    UserOrganizationScope::query()->create([
        'user_id' => $scopedUser->id,
        'organization_id' => $ownOrg->id,
        'scope_type' => OrganizationScopeType::Self,
        'is_active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    $insider = aiEmployeeIn($ownOrg);

    $this->actingAs($scopedUser->fresh())
        ->patch(route('employees.update', $insider), array_merge([
            'first_name' => 'Meron',
            'last_name' => 'Alemu',
            'status' => EmployeeStatus::Active->value,
        ], aiFields()))
        ->assertRedirect();

    expect($insider->fresh()->emergency_contact_name)->toBe('Alemu Bekele');
});

// ── 7. Public ID Checker privacy ──────────────────────────────────────────

it('never exposes address or emergency contact through the public id checker', function (): void {
    $org = aiOrg('AI-7');

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'code' => 'AI-7-U1',
        'name_en' => 'Checker Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'AI-7-P1',
        'title_en' => 'Checker Position',
        'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_number' => 'AI-7-EMP',
        'first_name' => 'Selam',
        'last_name' => 'Tesfaye',
        'full_name' => 'Selam Tesfaye',
        'phone' => '0911222333',
        'email' => 'selam@example.test',
        'address' => 'SECRETADDRESS Bole Woreda 03',
        'nationality' => 'Ethiopian',
        'emergency_contact_name' => 'SECRETKINNAME Alemu',
        'emergency_contact_phone' => '0911999888',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    $card = IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => 'AI-7-CARD',
        'status' => CardStatus::Active->value,
        'is_current' => true,
        'issued_at' => now()->subMonth(),
        'expires_at' => now()->addYear(),
    ]);

    app(CardQrPayloadService::class)->ensurePublicReference($card);
    $card->refresh();
    $uuid = $card->public_card_uuid;

    // Pre-consent scan page.
    $scanBody = $this->get(route('id-checker.show', $uuid))->assertOk()->getContent();

    // Verified payload — the maximum this feature ever publishes.
    app(PublicIdCheckerService::class)->sendOtp($uuid);
    $otp = PublicIdCheckOtp::query()->where('card_uuid', $uuid)->latest('created_at')->firstOrFail();
    $otp->forceFill(['otp_hash' => bcrypt('123456')])->save();

    $verifyBody = $this->postJson(route('id-checker.verify-otp', $uuid), ['otp' => '123456'])
        ->assertOk()
        ->assertJsonPath('verified', true)
        ->getContent();

    foreach ([$scanBody, $verifyBody] as $body) {
        foreach ([
            'SECRETADDRESS',
            'SECRETKINNAME',
            '0911999888',
            'emergency_contact_name',
            'emergency_contact_phone',
        ] as $secret) {
            expect($body)->not->toContain($secret);
        }
    }
});

it('keeps the additional fields out of the external organization data api', function (): void {
    // The API presenter selects an explicit column list; a new employees column
    // must not appear in it by accident.
    expect(OrganizationDataPresenter::EMPLOYEE_COLUMNS)
        ->not->toContain('address')
        ->not->toContain('nationality')
        ->not->toContain('emergency_contact_name')
        ->not->toContain('emergency_contact_phone');
});

/**
 * Employment type is how someone is engaged (permanent, contract). It is a
 * different thing from EmployeeStatus, which is the record lifecycle
 * (active, suspended). Neither may overwrite the other.
 */
it('offers the employee status choices on the create form', function (): void {
    $this->actingAs(aiManager())
        ->get(route('employees.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employees/Create'));

    // The dropdown is driven by the enum, so assert the source of truth.
    expect(EmploymentType::values())->toBe([
        'permanent', 'contract', 'temporary', 'probation', 'daily_labor', 'intern', 'other',
    ]);
});

it('creates an employee with a nationality and an employment type', function (): void {
    $org = aiOrg('AI-ET1');

    $this->actingAs(aiManager())
        ->post(route('employees.store'), [
            'first_name' => 'Selam',
            'last_name' => 'Tadesse',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $org->id,
            'effective_from' => now()->toDateString(),
            'nationality' => 'Ethiopian',
            'employment_type' => EmploymentType::Contract->value,
        ])
        ->assertRedirect();

    $employee = Employee::query()->where('full_name', 'Selam Tadesse')->firstOrFail();

    expect($employee->nationality)->toBe('Ethiopian')
        ->and($employee->employment_type)->toBe(EmploymentType::Contract)
        ->and($employee->status)->toBe(EmployeeStatus::Active);
});

it('updates the employment type without touching the record status', function (): void {
    $org = aiOrg('AI-ET2');
    $employee = aiEmployeeIn($org, ['status' => EmployeeStatus::Suspended, 'employment_type' => EmploymentType::Permanent]);

    $this->actingAs(aiManager())
        ->patch(route('employees.update', $employee), [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'status' => EmployeeStatus::Suspended->value,
            'employment_type' => EmploymentType::Probation->value,
            'nationality' => 'Kenyan',
        ])
        ->assertSessionHasNoErrors();

    $employee->refresh();

    // The lifecycle status survives an employment-type change.
    expect($employee->employment_type)->toBe(EmploymentType::Probation)
        ->and($employee->nationality)->toBe('Kenyan')
        ->and($employee->status)->toBe(EmployeeStatus::Suspended);
});

it('rejects an employment type outside the allowed list', function (): void {
    $org = aiOrg('AI-ET3');

    $this->actingAs(aiManager())
        ->post(route('employees.store'), [
            'first_name' => 'Bad',
            'last_name' => 'Type',
            'status' => EmployeeStatus::Active->value,
            'organization_id' => $org->id,
            'effective_from' => now()->toDateString(),
            'employment_type' => 'active',
        ])
        ->assertSessionHasErrors('employment_type');
});

it('shows the employment type on the employee detail page', function (): void {
    $org = aiOrg('AI-ET4');
    $employee = aiEmployeeIn($org, ['employment_type' => EmploymentType::DailyLabor]);

    $this->actingAs(aiManager())
        ->get(route('employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.employment_type', 'daily_labor')
            ->where('employee.employment_type_label', EmploymentType::DailyLabor->label()));
});
