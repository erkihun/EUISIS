<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function deprecationTestAdmin(): User
{
    foreach ([
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('DeprecationTestAdmin', 'web');
    $role->syncPermissions([
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function deprecationTestOrganization(string $suffix = ''): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'DEP-TEST-TYPE'],
        ['name_en' => 'Deprecation Test Type'],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'DEP-' . uniqid() . $suffix,
        'name_en' => 'Deprecation Test Org ' . $suffix,
        'status' => OrganizationStatus::Active,
    ]);
}

// ── GET /institution-offices redirects to /organization-units ──────────────

test('GET institution-offices index redirects to organization-units index', function (): void {
    $user = deprecationTestAdmin();

    $this->actingAs($user)
        ->get(route('institution-offices.index'))
        ->assertRedirect(route('organization-units.index'));
});

// ── GET /institution-offices/create still renders the create form ──────────

test('GET institution-offices create renders the organization unit create form', function (): void {
    $user = deprecationTestAdmin();

    $this->actingAs($user)
        ->get(route('institution-offices.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('InstitutionOffices/Create'));
});

// ── POST /institution-offices creates an OrganizationUnit, not InstitutionOffice ──

test('POST institution-offices store creates an OrganizationUnit record', function (): void {
    $user = deprecationTestAdmin();
    $org = deprecationTestOrganization('store-test');

    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['prefix' => 'OFF', 'name_en' => 'Office', 'sort_order' => 1, 'is_active' => true],
    );

    $code = 'DEP-OU-' . uniqid();

    $this->actingAs($user)
        ->post(route('institution-offices.store'), [
            'organization_id' => $org->id,
            'organization_unit_type_id' => $unitType->id,
            'code' => $code,
            'name_en' => 'Deprecation Test Office Unit',
            'status' => 'active',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('organization_units', [
        'organization_id' => $org->id,
        'code' => $code,
        'name_en' => 'Deprecation Test Office Unit',
    ]);

    // Must NOT create an institution_offices record
    $this->assertDatabaseMissing('institution_offices', [
        'name_en' => 'Deprecation Test Office Unit',
    ]);
});

// ── GET /institution-offices/{id} for a mapped unit redirects to org-unit show ──

test('GET institution-offices show redirects to mapped organization unit', function (): void {
    $user = deprecationTestAdmin();
    $org = deprecationTestOrganization('show-test');

    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['prefix' => 'OFF', 'name_en' => 'Office', 'sort_order' => 1, 'is_active' => true],
    );

    // Create an institution_office record so the FK constraint is satisfied
    $officeId = \Illuminate\Support\Str::uuid()->toString();
    DB::table('institution_offices')->insert([
        'id' => $officeId,
        'institution_id' => $org->id,
        'name_en' => 'Legacy Office For Show Test',
        'office_code' => 'DEP-SHOW-LEGACY-' . uniqid(),
        'office_level' => 'other',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'organization_unit_type_id' => $unitType->id,
        'unit_type' => 'office',
        'institution_office_id' => $officeId,
        'code' => 'DEP-SHOW-' . uniqid(),
        'name_en' => 'Deprecation Show Test Unit',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('institution-offices.show', $officeId))
        ->assertRedirect(route('organization-units.show', $unit));
});

// ── GET /institution-offices/{unknown-id} redirects to org-units index ────

test('GET institution-offices show with unmapped id redirects to organization-units index', function (): void {
    $user = deprecationTestAdmin();
    $unknownId = \Illuminate\Support\Str::uuid()->toString();

    $this->actingAs($user)
        ->get(route('institution-offices.show', $unknownId))
        ->assertRedirect(route('organization-units.index'));
});

// ── GET /institution-offices/{id}/edit redirects to mapped org unit edit ──

test('GET institution-offices edit redirects to mapped organization unit edit', function (): void {
    $user = deprecationTestAdmin();
    $org = deprecationTestOrganization('edit-test');

    $unitType = OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        ['prefix' => 'OFF', 'name_en' => 'Office', 'sort_order' => 1, 'is_active' => true],
    );

    // Create a real institution_office record so the FK constraint is satisfied
    $officeId = \Illuminate\Support\Str::uuid()->toString();
    DB::table('institution_offices')->insert([
        'id' => $officeId,
        'institution_id' => $org->id,
        'name_en' => 'Legacy Office For Edit Test',
        'office_code' => 'DEP-EDIT-LEGACY-' . uniqid(),
        'office_level' => 'other',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'organization_unit_type_id' => $unitType->id,
        'unit_type' => 'office',
        'institution_office_id' => $officeId,
        'code' => 'DEP-EDIT-' . uniqid(),
        'name_en' => 'Deprecation Edit Test Unit',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('institution-offices.edit', $officeId))
        ->assertRedirect(route('organization-units.edit', $unit));
});
