<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function makeOrgForIndexTest(): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'IDX-TEST-TYPE'],
        ['name_en' => 'Index Test Type', 'is_demo' => false],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'IDX-'.uniqid(),
        'name_en' => 'Index Test Org',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function ouIndexAdminUser(): User
{
    $perms = [
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
        'organization-units.update',
        'organization-units.archive',
        'organization-units.restore',
        'organization-units.manageHierarchy',
    ];

    foreach ($perms as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    $role = Role::findOrCreate('OUIndexAdmin', 'web');
    $role->syncPermissions($perms);

    $user = User::factory()->create();
    $user->assignRole('OUIndexAdmin');

    return $user;
}

function ouIndexViewerUser(): User
{
    Permission::findOrCreate('organization-units.viewAny', 'web');

    $role = Role::findOrCreate('OUIndexViewer', 'web');
    $role->syncPermissions(['organization-units.viewAny']);

    $user = User::factory()->create();
    $user->assignRole('OUIndexViewer');

    return $user;
}

// ── 1. Index page lists accessible organizations ─────────────────────────────

test('test_index_lists_accessible_organizations', function (): void {
    $user = ouIndexViewerUser();
    $org = makeOrgForIndexTest();

    $this->actingAs($user)
        ->get(route('organization-units.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('OrganizationUnits/Index')
            ->has('organizationTree')
            ->has('can'),
        );
});

// ── 2. Selecting org returns its units ───────────────────────────────────────

test('test_selecting_organization_returns_only_its_units', function (): void {
    $user = ouIndexViewerUser();
    $org = makeOrgForIndexTest();

    OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'SEL-'.uniqid(),
        'name_en' => 'Selected Org Unit',
        'status' => OrganizationUnitStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('organization-units.index', ['organization_id' => $org->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('OrganizationUnits/Index')
            ->where('selectedOrganization.id', $org->id)
            ->has('organizationUnits'),
        );
});

// ── 3. Units from another org are not included ───────────────────────────────

test('test_units_from_another_organization_are_not_included', function (): void {
    $user = ouIndexViewerUser();
    $org1 = makeOrgForIndexTest();
    $org2 = makeOrgForIndexTest();

    $unitInOrg2 = OrganizationUnit::query()->create([
        'organization_id' => $org2->id,
        'unit_type' => 'department',
        'code' => 'ORG2-'.uniqid(),
        'name_en' => 'Org2 Only Unit',
        'status' => OrganizationUnitStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('organization-units.index', ['organization_id' => $org1->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('OrganizationUnits/Index')
            ->where('selectedOrganization.id', $org1->id)
            ->where(
                'organizationUnits',
                fn ($tree) => ! collect($tree)->pluck('id')->contains($unitInOrg2->id),
            ),
        );
});

// ── 4. Root unit can be created without parent ───────────────────────────────

test('test_root_unit_can_be_created_without_parent', function (): void {
    $user = ouIndexAdminUser();
    $org = makeOrgForIndexTest();

    $this->actingAs($user)
        ->post(route('organization-units.store'), [
            'organization_id' => $org->id,
            'unit_type' => 'department',
            'code' => 'ROOT-'.uniqid(),
            'name_en' => 'Root Unit',
            'status' => 'active',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('organization_units', [
        'name_en' => 'Root Unit',
        'organization_id' => $org->id,
        'parent_unit_id' => null,
    ]);
});

// ── 5. Parent unit options limited to selected org ───────────────────────────

test('test_parent_unit_limited_to_selected_organization', function (): void {
    $user = ouIndexAdminUser();
    $org = makeOrgForIndexTest();

    $this->actingAs($user)
        ->get(route('organization-units.create', ['organization_id' => $org->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('OrganizationUnits/Create')
            ->where('selectedOrg.id', $org->id)
            ->has('parentUnits'),
        );
});

// ── 6. Parent unit from another org is rejected ──────────────────────────────

test('test_parent_unit_from_another_organization_is_rejected', function (): void {
    $user = ouIndexAdminUser();
    $org1 = makeOrgForIndexTest();
    $org2 = makeOrgForIndexTest();

    $parentInOrg2 = OrganizationUnit::query()->create([
        'organization_id' => $org2->id,
        'unit_type' => 'directorate',
        'code' => 'PORG2-'.uniqid(),
        'name_en' => 'Parent in Org2',
        'status' => OrganizationUnitStatus::Active,
    ]);

    $this->actingAs($user)
        ->post(route('organization-units.store'), [
            'organization_id' => $org1->id,
            'parent_unit_id' => $parentInOrg2->id,
            'unit_type' => 'department',
            'name_en' => 'Child in Org1',
            'status' => 'active',
        ])
        ->assertSessionHasErrors('parent_unit_id');
});

// ── 7. Create unit writes audit log ─────────────────────────────────────────

test('test_create_unit_writes_audit_log', function (): void {
    $user = ouIndexAdminUser();
    $org = makeOrgForIndexTest();

    $this->actingAs($user)
        ->post(route('organization-units.store'), [
            'organization_id' => $org->id,
            'unit_type' => 'department',
            'code' => 'AUDIT-'.uniqid(),
            'name_en' => 'Audit Test Unit',
            'status' => 'active',
        ])
        ->assertRedirect();

    $unit = OrganizationUnit::query()
        ->where('name_en', 'Audit Test Unit')
        ->where('organization_id', $org->id)
        ->latest()
        ->first();

    expect($unit)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => OrganizationUnit::class,
        'auditable_id' => $unit?->id,
    ]);
});

// ── 8. Delete is permission protected ───────────────────────────────────────

test('test_delete_is_permission_protected', function (): void {
    $viewer = ouIndexViewerUser();
    $org = makeOrgForIndexTest();

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'PROT-'.uniqid(),
        'name_en' => 'Protected Unit',
        'status' => OrganizationUnitStatus::Active,
    ]);

    $this->actingAs($viewer)
        ->post(route('organization-units.archive', $unit->id))
        ->assertForbidden();
});

// ── 9. Restore is permission protected ──────────────────────────────────────

test('test_restore_is_permission_protected', function (): void {
    $viewer = ouIndexViewerUser();
    $org = makeOrgForIndexTest();

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'RPROT-'.uniqid(),
        'name_en' => 'Restore Protected',
        'status' => OrganizationUnitStatus::Active,
    ]);
    $unit->delete();

    $this->actingAs($viewer)
        ->post(route('organization-units.restore', $unit->id))
        ->assertForbidden();
});

// ── 10. EN / AM localization keys exist ─────────────────────────────────────

test('test_en_am_localization_keys_exist', function (): void {
    $enFile = base_path('lang/en/organization-units.php');
    $amFile = base_path('lang/am/organization-units.php');

    expect(file_exists($enFile))->toBeTrue()
        ->and(file_exists($amFile))->toBeTrue();

    $en = require $enFile;
    $am = require $amFile;

    $requiredKeys = [
        'organization_units',
        'created_successfully',
        'updated_successfully',
        'parent_must_belong_to_same_org',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $en))->toBeTrue("Missing EN key: {$key}");
        expect(array_key_exists($key, $am))->toBeTrue("Missing AM key: {$key}");
    }
});
