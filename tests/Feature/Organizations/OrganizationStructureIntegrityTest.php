<?php

declare(strict_types=1);

use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Actions\OrganizationTypes\DeleteOrganizationTypeAction;
use App\Actions\OrganizationTypes\UpdateOrganizationTypeAction;
use App\Actions\OrganizationUnits\ArchiveOrganizationUnitAction;
use App\Actions\OrganizationUnitTypes\ArchiveOrganizationUnitTypeAction;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationRelationships\RelationshipValidationService;
use App\Services\Organizations\OrganizationTreeService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The rest of the registration chain — organization types, unit types, units,
 * reporting lines and hierarchy versions — keeps records that others already
 * point at from being deleted, moved or overwritten by accident.
 */

beforeEach(function (): void {
    app()->setLocale('en');

    $permissions = [
        'organization-types.delete', 'organization-types.update',
        'organization-units.create', 'organization-units.update', 'organization-units.archive', 'organization-units.view',
        'organization-unit-types.archive',
    ];

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo($permissions);
});

function structureAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function structureType(string $code, ?array $parentTypes = null): OrganizationType
{
    return OrganizationType::query()->create(['code' => $code, 'name_en' => "Type {$code}", 'parent_allowed_types' => $parentTypes]);
}

function structureOrganization(OrganizationType $type, string $code): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => "Organization {$code}",
        'name_am' => "ተቋም {$code}",
        'status' => OrganizationStatus::Active,
    ]);
}

function structureUnitType(string $code = 'DIR', bool $active = true): OrganizationUnitType
{
    return OrganizationUnitType::query()->create(['code' => $code, 'name_en' => "Unit type {$code}", 'is_active' => $active, 'sort_order' => 0]);
}

function structureUnit(Organization $organization, OrganizationUnitType $type, string $code, string $nameEn, ?OrganizationUnit $parent = null): OrganizationUnit
{
    return OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'organization_unit_type_id' => $type->id,
        'parent_unit_id' => $parent?->id,
        'unit_type' => 'department',
        'code' => $code,
        'name_en' => $nameEn,
        'name_am' => "ክፍል {$code}",
        'status' => 'active',
    ]);
}

function expectValidationError(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->all())->toContain($message);

        return;
    }

    test()->fail('Expected a validation error: '.$message);
}

// ── Organization types ──────────────────────────────────────────────────────

it('keeps an organization type that organizations still use', function (): void {
    $type = structureType('BUREAU');
    structureOrganization($type, 'BUR-1');

    expectValidationError(
        fn () => app(DeleteOrganizationTypeAction::class)->execute($type, structureAdmin()),
        __('organization-types.cannot_delete_in_use', ['count' => 1]),
    );

    expect(OrganizationType::query()->whereKey($type->id)->exists())->toBeTrue();
});

it('reports the refusal as a toast when the delete button is used', function (): void {
    $type = structureType('BUREAU');
    structureOrganization($type, 'BUR-1');

    $this->actingAs(structureAdmin())
        ->from(route('organization-types.index'))
        ->delete(route('organization-types.destroy', $type))
        ->assertRedirect(route('organization-types.index'))
        ->assertSessionHas('error', __('organization-types.cannot_delete_in_use', ['count' => 1]));
});

it('keeps an organization type another type allows as its parent', function (): void {
    $city = structureType('CITY');
    structureType('BUREAU', ['CITY']);

    expectValidationError(
        fn () => app(DeleteOrganizationTypeAction::class)->execute($city, structureAdmin()),
        __('organization-types.cannot_delete_allowed_parent', ['names' => 'Type BUREAU']),
    );
});

it('follows a renamed type code into the parent rules of other types', function (): void {
    $city = structureType('CITY');
    $bureau = structureType('BUREAU', ['CITY', 'OTHER']);

    app(UpdateOrganizationTypeAction::class)->execute(['code' => 'CITYADM'], $city, structureAdmin());

    expect($bureau->fresh()->parent_allowed_types)->toBe(['CITYADM', 'OTHER'])
        ->and($bureau->fresh()->allowsParentType($city->fresh()))->toBeTrue();
});

// ── Unit types ──────────────────────────────────────────────────────────────

it('keeps a unit type that units still use', function (): void {
    $unitType = structureUnitType();
    structureUnit(structureOrganization(structureType('BUREAU'), 'BUR-1'), $unitType, 'U-1', 'Finance Directorate');

    expectValidationError(
        fn () => app(ArchiveOrganizationUnitTypeAction::class)->execute($unitType, structureAdmin()),
        __('organization-units.unit_type_in_use', ['count' => 1]),
    );

    expect(OrganizationUnitType::query()->whereKey($unitType->id)->exists())->toBeTrue();
});

// ── Units ───────────────────────────────────────────────────────────────────

it('does not build a new unit on an inactive unit type', function (): void {
    $organization = structureOrganization(structureType('BUREAU'), 'BUR-1');
    $inactive = structureUnitType('OLD', active: false);

    $this->actingAs(structureAdmin())
        ->post(route('organization-units.store'), [
            'organization_id' => $organization->id,
            'organization_unit_type_id' => $inactive->id,
            'code' => 'U-1',
            'name_en' => 'Finance Directorate',
            'name_am' => 'የፋይናንስ ዳይሬክቶሬት',
            'status' => 'active',
        ])
        ->assertSessionHasErrors('organization_unit_type_id');
});

it('refuses two units with the same name under one parent, but not under different parents', function (): void {
    $organization = structureOrganization(structureType('BUREAU'), 'BUR-1');
    $unitType = structureUnitType();
    $head = structureUnit($organization, $unitType, 'U-HEAD', 'Head Office');
    $branch = structureUnit($organization, $unitType, 'U-BRANCH', 'Branch Office');
    structureUnit($organization, $unitType, 'U-FIN', 'Finance Directorate', $head);
    $payload = [
        'organization_id' => $organization->id,
        'organization_unit_type_id' => $unitType->id,
        'name_en' => '  finance   DIRECTORATE ',
        'name_am' => 'ሌላ ክፍል',
        'status' => 'active',
    ];

    $this->actingAs(structureAdmin())
        ->post(route('organization-units.store'), $payload + ['code' => 'U-2', 'parent_unit_id' => $head->id])
        ->assertSessionHasErrors('name_en');

    $this->actingAs(structureAdmin())
        ->post(route('organization-units.store'), $payload + ['code' => 'U-3', 'parent_unit_id' => $branch->id])
        ->assertSessionHasNoErrors();
});

it('keeps a unit in the organization it was created in', function (): void {
    $type = structureType('BUREAU');
    $organization = structureOrganization($type, 'BUR-1');
    $other = structureOrganization($type, 'BUR-2');
    $unit = structureUnit($organization, structureUnitType(), 'U-1', 'Finance Directorate');

    $this->actingAs(structureAdmin())
        ->patch(route('organization-units.update', $unit), [
            'organization_id' => $other->id,
            'organization_unit_type_id' => $unit->organization_unit_type_id,
            'code' => 'U-1',
            'name_en' => 'Finance Directorate',
            'name_am' => 'ክፍል U-1',
            'status' => 'active',
        ])
        ->assertSessionHasErrors(['organization_id' => __('organization-units.organization_cannot_change')]);

    expect($unit->fresh()->organization_id)->toBe($organization->id);
});

it('does not archive a unit that still has sub-units or positions', function (): void {
    $organization = structureOrganization(structureType('BUREAU'), 'BUR-1');
    $unitType = structureUnitType();
    $parent = structureUnit($organization, $unitType, 'U-1', 'Finance Directorate');
    structureUnit($organization, $unitType, 'U-2', 'Budget Team', $parent);
    $withPosition = structureUnit($organization, $unitType, 'U-3', 'Audit Directorate');
    Position::query()->create([
        'organization_id' => $organization->id,
        'organization_unit_id' => $withPosition->id,
        'job_position_code' => 'POS-1',
        'title_en' => 'Auditor',
        'is_active' => true,
    ]);

    expectValidationError(
        fn () => app(ArchiveOrganizationUnitAction::class)->execute($parent, structureAdmin()),
        __('organization-units.cannot_archive_with_children', ['count' => 1]),
    );
    expectValidationError(
        fn () => app(ArchiveOrganizationUnitAction::class)->execute($withPosition, structureAdmin()),
        __('organization-units.cannot_archive_with_positions', ['count' => 1]),
    );

    expect(OrganizationUnit::query()->whereKey([$parent->id, $withPosition->id])->count())->toBe(2);
});

// ── Reporting lines ─────────────────────────────────────────────────────────

it('does not let a unit report to itself', function (): void {
    $unit = structureUnit(structureOrganization(structureType('BUREAU'), 'BUR-1'), structureUnitType(), 'U-1', 'Finance Directorate');

    expectValidationError(
        fn () => app(RelationshipValidationService::class)->validateUnitRelationship([
            'source_unit_id' => $unit->id,
            'target_type' => 'organization_unit',
            'target_id' => $unit->id,
            'relationship_type' => 'functional_reporting',
            'status' => 'active',
        ]),
        __('relationships.validation.target_is_source'),
    );
});

// ── Hierarchy versions ──────────────────────────────────────────────────────

it('holds a link made in the hierarchy editor to the organization type rules', function (): void {
    $city = structureOrganization(structureType('CITY'), 'CITY-1');
    $bureau = structureOrganization(structureType('SUBCITY'), 'SC-1');
    $woreda = structureOrganization(structureType('WOREDA', ['SUBCITY']), 'W-1');
    $draft = HierarchyVersion::query()->create(['version_name' => 'Draft', 'status' => HierarchyVersionStatus::Draft, 'effective_from' => '2026-01-01']);

    expectValidationError(
        fn () => app(OrganizationTreeService::class)->validateEdgeMutation($draft, structureAdmin(), $city->id, $woreda->id),
        __('organizations.invalid_parent_organization_type'),
    );

    app(OrganizationTreeService::class)->validateEdgeMutation($draft, structureAdmin(), $bureau->id, $woreda->id);
});

it('does not publish a draft copied before the current version went live', function (): void {
    $type = structureType('ANY');
    $parent = structureOrganization($type, 'P-1');
    $child = structureOrganization($type, 'C-1');
    $admin = structureAdmin();

    Carbon::setTestNow('2026-11-01 08:00:00');
    $stale = HierarchyVersion::query()->create(['version_name' => 'Old draft', 'status' => HierarchyVersionStatus::Draft, 'effective_from' => '2027-03-01']);
    $current = HierarchyVersion::query()->create(['version_name' => 'Live draft', 'status' => HierarchyVersionStatus::Draft, 'effective_from' => '2027-01-01']);
    foreach ([$stale, $current] as $version) {
        OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id, 'relationship_type' => 'reports_to']);
    }

    Carbon::setTestNow('2026-11-01 09:00:00');
    app(PublishHierarchyVersionAction::class)->execute($current, $admin);

    expectValidationError(
        fn () => app(PublishHierarchyVersionAction::class)->execute($stale->fresh(), $admin),
        __('hierarchy-versions.publish_stale_draft', ['version' => 'Live draft']),
    );

    Carbon::setTestNow();
});

it('does not publish a draft that takes effect before the current version', function (): void {
    $type = structureType('ANY');
    $parent = structureOrganization($type, 'P-1');
    $child = structureOrganization($type, 'C-1');
    $admin = structureAdmin();

    Carbon::setTestNow('2026-11-01 08:00:00');
    $current = HierarchyVersion::query()->create(['version_name' => 'Live', 'status' => HierarchyVersionStatus::Draft, 'effective_from' => '2027-01-01']);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $current->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id, 'relationship_type' => 'reports_to']);
    app(PublishHierarchyVersionAction::class)->execute($current, $admin);

    Carbon::setTestNow('2026-11-01 10:00:00');
    $draft = HierarchyVersion::query()->create(['version_name' => 'Backdated', 'status' => HierarchyVersionStatus::Draft, 'effective_from' => '2026-12-01']);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $draft->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id, 'relationship_type' => 'reports_to']);

    expectValidationError(
        fn () => app(PublishHierarchyVersionAction::class)->execute($draft, $admin),
        __('hierarchy-versions.publish_effective_before_current', ['date' => '2027-01-01']),
    );

    Carbon::setTestNow();
});
