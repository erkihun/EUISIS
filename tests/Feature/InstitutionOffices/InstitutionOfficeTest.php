<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\CodeRuleScopeStrategy;
use App\Enums\InstitutionOfficeLevel;
use App\Enums\InstitutionOfficeStatus;
use App\Enums\OrganizationStatus;
use App\Models\CodeRule;
use App\Models\InstitutionOffice;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeInstitution(): Organization
{
    $type = OrganizationType::query()->firstOrCreate(
        ['code' => 'TEST-INST-TYPE'],
        ['name_en' => 'Test Institution Type', 'is_demo' => false],
    );

    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'INST-'.uniqid(),
        'name_en' => 'Test Institution',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function ioAdminUser(): User
{
    foreach ([
        'institution-offices.viewAny',
        'institution-offices.view',
        'institution-offices.create',
        'institution-offices.update',
        'institution-offices.delete',
        'institution-offices.restore',
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
        'organization-units.update',
        'organization-units.delete',
        'organization-units.restore',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    $role = Role::findOrCreate('IOAdmin', 'web');
    $role->syncPermissions([
        'institution-offices.viewAny',
        'institution-offices.view',
        'institution-offices.create',
        'institution-offices.update',
        'institution-offices.delete',
        'institution-offices.restore',
        'organization-units.viewAny',
        'organization-units.view',
        'organization-units.create',
        'organization-units.update',
        'organization-units.delete',
        'organization-units.restore',
    ]);

    $user = User::factory()->create();
    $user->assignRole('IOAdmin');

    return $user;
}

function ioViewerUser(): User
{
    Permission::findOrCreate('institution-offices.viewAny', 'web');
    $role = Role::findOrCreate('IOViewer', 'web');
    $role->syncPermissions(['institution-offices.viewAny']);

    $user = User::factory()->create();
    $user->assignRole('IOViewer');

    return $user;
}

function institutionOfficeCodeRule(): CodeRule
{
    return CodeRule::query()->create([
        'entity_type' => CodeRuleEntityType::OrganizationUnit->value,
        'active_scope_key' => CodeRule::buildActiveScopeKey(CodeRuleEntityType::OrganizationUnit),
        'name_en' => 'Institution Office Code',
        'prefix' => 'IO',
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'sequence_length' => 4,
        'next_number' => 1,
        'sequence_scope_strategy' => CodeRuleScopeStrategy::Global,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'is_active' => true,
        'allow_manual_override' => true,
        'require_approval_for_override' => false,
    ]);
}

function institutionOfficeUnitType(): OrganizationUnitType
{
    return OrganizationUnitType::query()->firstOrCreate(
        ['code' => 'office'],
        [
            'prefix' => 'OFF',
            'name_en' => 'Office',
            'sort_order' => 1,
            'is_active' => true,
        ],
    );
}

// ── 1. Index redirects to org-units for authorised user (deprecated) ─────────

test('authorised_user_can_view_institution_offices_index', function (): void {
    $user = ioAdminUser();
    $this->actingAs($user)->get(route('institution-offices.index'))
        ->assertRedirect(route('organization-units.index'));
});

// ── 2. Guest is redirected to login (redirect chain goes through login) ───────

test('guest_is_redirected_from_institution_offices_index', function (): void {
    // index() now redirects to organization-units.index which requires auth,
    // so guests ultimately end up at login.
    $this->get(route('institution-offices.index'))
        ->assertRedirect(route('login'));
});

// ── 3. Authenticated user without org-unit permission — index now redirects ──

test('user_without_permission_cannot_view_index', function (): void {
    // The index() method now issues a plain redirect (no auth check),
    // so any authenticated user gets a redirect to organization-units.index.
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('institution-offices.index'))
        ->assertRedirect(route('organization-units.index'));
});

// ── 4. Can create institution office ─────────────────────────────────────────

test('admin_can_create_institution_office', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();
    $unitType = institutionOfficeUnitType();
    $code = 'OFF-'.uniqid();

    $response = $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $institution->id,
        'organization_unit_type_id' => $unitType->id,
        'code' => $code,
        'name_en' => 'City Head Office',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->where('code', $code)->firstOrFail();

    $response->assertRedirect(route('organization-units.show', $unit));
    $this->assertDatabaseHas('organization_units', [
        'organization_id' => $institution->id,
        'organization_unit_type_id' => $unitType->id,
        'code' => $code,
        'name_en' => 'City Head Office',
        'unit_type' => 'office',
    ]);
});

// ── 5. Validation fails if institution_id missing ─────────────────────────────

test('store_fails_validation_without_institution_id', function (): void {
    $user = ioAdminUser();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_unit_type_id' => institutionOfficeUnitType()->id,
        'code' => 'OFF-X',
        'name_en' => 'Test',
    ])->assertSessionHasErrors(['organization_id']);
});

// ── 6. Validation fails if office_code missing ────────────────────────────────

test('store_generates_office_code_when_missing', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();
    $unitType = institutionOfficeUnitType();
    institutionOfficeCodeRule();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $institution->id,
        'organization_unit_type_id' => $unitType->id,
        'name_en' => 'Test',
    ])->assertRedirect();

    $this->assertDatabaseHas('organization_units', [
        'organization_id' => $institution->id,
        'code' => 'IO-0001',
        'name_en' => 'Test',
    ]);
});

// ── 7. Office code must be unique ─────────────────────────────────────────────

test('office_code_must_be_unique', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();
    $unitType = institutionOfficeUnitType();
    $code = 'UNIQUE-'.uniqid();

    OrganizationUnit::query()->create([
        'organization_id' => $institution->id,
        'organization_unit_type_id' => $unitType->id,
        'unit_type' => 'office',
        'code' => $code,
        'name_en' => 'First',
        'status' => 'active',
    ]);

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $institution->id,
        'organization_unit_type_id' => $unitType->id,
        'code' => $code,
        'name_en' => 'Second',
    ])->assertSessionHasErrors(['code']);
});

// ── 8. Parent must belong to same institution ─────────────────────────────────

test('parent_office_must_belong_to_same_institution', function (): void {
    $user = ioAdminUser();
    $inst1 = makeInstitution();
    $inst2 = makeInstitution();
    $unitType = institutionOfficeUnitType();

    $parent = OrganizationUnit::query()->create([
        'organization_id' => $inst1->id,
        'organization_unit_type_id' => $unitType->id,
        'unit_type' => 'office',
        'code' => 'PARENT-'.uniqid(),
        'name_en' => 'Parent Office',
        'status' => 'active',
    ]);

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $inst2->id,
        'organization_unit_type_id' => $unitType->id,
        'parent_unit_id' => $parent->id,
        'code' => 'CHILD-'.uniqid(),
        'name_en' => 'Child',
    ])->assertSessionHasErrors(['parent_unit_id']);
});

// ── 9. Show redirects to mapped org-unit or org-units index (deprecated) ─────

test('admin_can_view_institution_office', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    $office = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::City->value,
        'office_code' => 'SHOW-'.uniqid(),
        'name_en' => 'Show Office',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);

    // No mapped org unit exists, so it redirects to org-units index
    $this->actingAs($user)->get(route('institution-offices.show', $office))
        ->assertRedirect(route('organization-units.index'));
});

// ── 10. Update redirects to org-units index (deprecated) ─────────────────────

test('admin_can_update_institution_office', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    $office = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::City->value,
        'office_code' => 'UPD-'.uniqid(),
        'name_en' => 'Original Name',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);

    // PATCH now redirects to org-units.index (no longer updates institution_offices)
    $this->actingAs($user)->patch(route('institution-offices.update', $office), [
        'name_en' => 'Updated Name',
    ])->assertRedirect(route('organization-units.index'));
});

// ── 11. Delete redirects to org-units index (deprecated) ─────────────────────

test('admin_can_delete_institution_office', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    $office = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::Branch->value,
        'office_code' => 'DEL-'.uniqid(),
        'name_en' => 'To Delete',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);

    // DELETE now redirects to org-units.index (no longer deletes institution_offices)
    $this->actingAs($user)->delete(route('institution-offices.destroy', $office))
        ->assertRedirect(route('organization-units.index'));
});

// ── 12. Restore redirects to org-units index (deprecated) ────────────────────

test('admin_can_restore_institution_office', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    $office = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::Branch->value,
        'office_code' => 'RST-'.uniqid(),
        'name_en' => 'To Restore',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);
    $office->delete();

    // Restore now redirects to org-units.index (no longer restores institution_offices)
    $this->actingAs($user)->post(route('institution-offices.restore', $office->id))
        ->assertRedirect(route('organization-units.index'));
});

// ── 13. Move redirects to org-units index (deprecated) ───────────────────────

test('admin_can_move_institution_office', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    $child = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::Branch->value,
        'office_code' => 'CH-'.uniqid(),
        'name_en' => 'Child',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);

    // Move now redirects to org-units.index (no longer moves institution_offices)
    $this->actingAs($user)->post(route('institution-offices.move', $child), [
        'parent_office_id' => Str::uuid()->toString(),
    ])->assertRedirect(route('organization-units.index'));
});

// ── 14. Move circular check — now redirects (deprecated) ─────────────────────

test('move_blocked_for_circular_hierarchy', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    $parent = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::City->value,
        'office_code' => 'CYC-P-'.uniqid(),
        'name_en' => 'Parent',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);

    // Move is now a no-op redirect — circular check no longer applies
    $this->actingAs($user)->post(route('institution-offices.move', $parent), [
        'parent_office_id' => Str::uuid()->toString(),
    ])->assertRedirect(route('organization-units.index'));
});

// ── 15. Viewer cannot create ──────────────────────────────────────────────────

test('viewer_cannot_create_institution_office', function (): void {
    $user = ioViewerUser();
    $institution = makeInstitution();
    $unitType = institutionOfficeUnitType();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $institution->id,
        'organization_unit_type_id' => $unitType->id,
        'code' => 'VIEW-'.uniqid(),
        'name_en' => 'Test',
    ])->assertForbidden();
});

// ── 16. Tree endpoint redirects to org-unit tree (deprecated) ────────────────

test('tree_endpoint_returns_json', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    // institutions.offices.tree now redirects to organizations.units.tree
    $this->actingAs($user)
        ->get(route('institutions.offices.tree', $institution))
        ->assertRedirect(route('organizations.units.tree', $institution));
});

// ── 17. Model casts work correctly ────────────────────────────────────────────

test('model_casts_office_level_enum', function (): void {
    $institution = makeInstitution();

    $office = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::SubCity->value,
        'office_code' => 'CAST-'.uniqid(),
        'name_en' => 'Cast Test',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);

    expect($office->fresh()->office_level)->toBeInstanceOf(InstitutionOfficeLevel::class);
    expect($office->fresh()->office_level)->toBe(InstitutionOfficeLevel::SubCity);
});

// ── 18. Head office cannot have parent ───────────────────────────────────────

test('office_unit_can_be_created_without_parent_unit', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();
    $unitType = institutionOfficeUnitType();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $institution->id,
        'organization_unit_type_id' => $unitType->id,
        'code' => 'HEAD-'.uniqid(),
        'name_en' => 'Head Office',
        'status' => 'active',
    ])->assertRedirect();

    $this->assertDatabaseHas('organization_units', [
        'organization_id' => $institution->id,
        'parent_unit_id' => null,
        'name_en' => 'Head Office',
    ]);
});

// ── 19. Invalid office_level is rejected ──────────────────────────────────────

test('invalid_organization_unit_type_is_rejected', function (): void {
    $user = ioAdminUser();
    $institution = makeInstitution();

    $this->actingAs($user)->post(route('institution-offices.store'), [
        'organization_id' => $institution->id,
        'organization_unit_type_id' => (string) Str::uuid(),
        'code' => 'INV-'.uniqid(),
        'name_en' => 'Bad Level',
    ])->assertSessionHasErrors(['organization_unit_type_id']);
});

// ── 20. SoftDelete: deleted offices don't appear in default query ─────────────

test('soft_deleted_office_does_not_appear_in_default_query', function (): void {
    $institution = makeInstitution();

    $office = InstitutionOffice::query()->create([
        'institution_id' => $institution->id,
        'office_level' => InstitutionOfficeLevel::Woreda->value,
        'office_code' => 'SOFT-'.uniqid(),
        'name_en' => 'Soft Deleted',
        'status' => InstitutionOfficeStatus::Active->value,
    ]);
    $office->delete();

    $found = InstitutionOffice::query()->where('id', $office->id)->first();
    expect($found)->toBeNull();

    $foundWithTrashed = InstitutionOffice::withTrashed()->where('id', $office->id)->first();
    expect($foundWithTrashed)->not->toBeNull();
});
