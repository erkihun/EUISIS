<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\HierarchyVersion;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\Organization;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationEdge;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitRelationship;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\ResponsibilityType;
use App\Models\UnitDesignProposal;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationDesign\OrganizationDesignValidationService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 6: structure validation
 * (ERROR / WARNING / INFO, §44, 79, 119), the approval gate on design
 * studies, the impact view (§114–115) and the design-side structure page
 * with published hierarchy versions and functional relationships (§40–43).
 * Tests 43–48.
 */

beforeEach(function (): void {
    foreach (['organization-design.viewAny', 'organization-design.view', 'organization-design.create', 'organization-design.update', 'organization-design.review', 'organization-design.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Structure Reviewer', 'web')->syncPermissions(['organization-design.viewAny', 'organization-design.view', 'organization-design.create', 'organization-design.update', 'organization-design.review']);
    Role::findOrCreate('Structure Signoff', 'web')->syncPermissions(['organization-design.viewAny', 'organization-design.view', 'organization-design.review', 'organization-design.approve']);
    ResponsibilityType::query()->firstOrCreate(['code' => 'RESPONSIBLE'], ['name_en' => 'Responsible', 'is_exclusive' => false, 'covers_object' => true, 'is_active' => true]);
});

function svOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SV-BUREAU'], ['name_en' => 'SV Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'SV-'.$suffix.'-'.uniqid(), 'name_en' => 'SV Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function svUser(Organization $organization, string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true, 'effective_from' => now()->subDay()->toDateString()]);

    return $user->fresh();
}

function svUnit(Organization $organization, string $code, ?string $parentId = null, array $overrides = []): OrganizationUnit
{
    $type = OrganizationUnitType::query()->firstOrCreate(['code' => 'SV-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);

    return OrganizationUnit::query()->create(['organization_id' => $organization->id, 'parent_unit_id' => $parentId, 'organization_unit_type_id' => $type->id, 'code' => $code, 'name_en' => 'Unit '.$code, 'unit_type' => 'directorate', 'status' => 'active', ...$overrides]);
}

function svPosition(Organization $organization, OrganizationUnit $unit, string $code, bool $active = true): Position
{
    return Position::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $unit->id, 'job_position_code' => $code, 'title_en' => 'Officer '.$code, 'is_active' => $active]);
}

function svFunction(Organization $organization, string $code): OrganizationFunction
{
    $type = LegalInstrumentType::query()->firstOrCreate(['code' => 'PROCLAMATION'], ['name_en' => 'Proclamation', 'sort_order' => 10]);
    $mandate = InstitutionMandate::query()->where('organization_id', $organization->id)->first() ?? InstitutionMandate::query()->create([
        'organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => 'MND-'.substr(uniqid(), -4),
        'title_en' => 'Mandate', 'legal_instrument_type_id' => $type->id, 'legal_instrument_number' => '1/2020', 'effective_from' => '2020-01-01', 'status' => OrganizationDesignStatus::Published,
    ]);
    $function = OrganizationFunction::query()->create(['organization_id' => $organization->id, 'mandate_id' => $mandate->id, 'code' => $code, 'title_en' => $code, 'path' => '', 'depth' => 0]);
    $function->forceFill(['path' => $function->id])->save();

    return $function;
}

function svStudy(Organization $organization, User $preparer): OrganizationDesignStudy
{
    return OrganizationDesignStudy::query()->create(['organization_id' => $organization->id, 'reference_number' => 'ODS-T-'.substr(uniqid(), -5), 'title_en' => 'Study', 'study_type' => 'restructure', 'status' => OrganizationDesignStatus::Draft, 'prepared_by' => $preparer->id, 'created_by' => $preparer->id]);
}

/** @return list<string> */
function svCodes(array $findings, ?string $severity = null): array
{
    return collect($findings)->when($severity, fn ($c) => $c->where('severity', $severity))->pluck('code')->sort()->values()->all();
}

// 43 ─────────────────────────────────────────────────────────────────────────
test('structure validation grades the current unit tree: cycles and inactive-parent chains are errors, missing coverage a warning, stats are information', function (): void {
    $organization = svOrganization('A');
    $root = svUnit($organization, 'U-ROOT');
    $inactive = svUnit($organization, 'U-OFF', $root->id, ['status' => 'inactive']);
    $orphanActive = svUnit($organization, 'U-UNDER-OFF', $inactive->id);
    $untyped = svUnit($organization, 'U-NOTYPE', $root->id, ['organization_unit_type_id' => null]);
    svPosition($organization, $inactive, 'P-IN-OFF');
    svPosition($organization, $root, 'P-ROOT');
    $uncovered = svFunction($organization, 'F-1');
    // A cycle written straight into the table, the way bad data arrives.
    $a = svUnit($organization, 'U-A', $root->id);
    $b = svUnit($organization, 'U-B', $a->id);
    OrganizationUnit::query()->whereKey($a->id)->update(['parent_unit_id' => $b->id]);

    $report = app(OrganizationDesignValidationService::class)->validateStructure($organization->id);

    expect($report['summary']['errors'])->toBeGreaterThanOrEqual(3)
        ->and(svCodes($report['findings'], 'error'))->toContain('unit_cycle', 'inactive_unit_active_positions', 'active_unit_inactive_parent')
        ->and(svCodes($report['findings'], 'warning'))->toContain('unit_without_type', 'function_uncovered', 'unit_without_positions')
        ->and(svCodes($report['findings'], 'info'))->toContain('span_of_control')
        ->and(collect($report['findings'])->firstWhere('code', 'unit_cycle')['subject']['code'] ?? null)->toBeIn(['U-A', 'U-B'])
        ->and(collect($report['findings'])->firstWhere('code', 'function_uncovered')['subject']['code'])->toBe('F-1')
        ->and($report['ok'])->toBeFalse();
    expect(OrganizationUnit::query()->whereKey($orphanActive->id)->exists())->toBeTrue()->and($untyped->organization_unit_type_id)->toBeNull()->and($uncovered->code)->toBe('F-1');
});

// 44 ─────────────────────────────────────────────────────────────────────────
test('study validation grades its proposals: deactivating a unit with active positions or live children, and moves that cycle, are errors', function (): void {
    $organization = svOrganization('B');
    $preparer = svUser($organization, 'Structure Reviewer');
    $root = svUnit($organization, 'U-ROOT');
    $busy = svUnit($organization, 'U-BUSY', $root->id);
    $child = svUnit($organization, 'U-CHILD', $busy->id);
    svPosition($organization, $busy, 'P-1');
    $study = svStudy($organization, $preparer);

    UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'deactivate', 'existing_unit_id' => $busy->id, 'name_en' => 'Unit U-BUSY']);
    UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'move', 'existing_unit_id' => $root->id, 'parent_existing_unit_id' => $child->id, 'name_en' => 'Unit U-ROOT']);
    $bare = UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'create', 'name_en' => 'New unit', 'parent_existing_unit_id' => $root->id]);

    $report = app(OrganizationDesignValidationService::class)->validateStudy($study);

    expect(svCodes($report['findings'], 'error'))->toContain('deactivate_unit_has_active_positions', 'deactivate_unit_has_children', 'move_creates_cycle', 'proposal_without_unit_type')
        ->and(svCodes($report['findings'], 'warning'))->toContain('proposal_without_responsibilities')
        ->and($report['ok'])->toBeFalse();

    // The page carries the same report; React never decides.
    $this->actingAs($preparer)->get(route('organization-design.studies.show', $study))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Studies/Show')->where('validation.ok', false)->has('validation.findings'));
    expect($bare->exists)->toBeTrue();
});

// 45 ─────────────────────────────────────────────────────────────────────────
test('a study with errors cannot be approved; once the errors are gone it can', function (): void {
    $organization = svOrganization('C');
    $preparer = svUser($organization, 'Structure Reviewer');
    $approver = svUser($organization, 'Structure Signoff');
    $root = svUnit($organization, 'U-ROOT');
    $busy = svUnit($organization, 'U-BUSY', $root->id);
    svPosition($organization, $busy, 'P-1');
    $study = svStudy($organization, $preparer);
    $bad = UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'deactivate', 'existing_unit_id' => $busy->id, 'name_en' => 'Unit U-BUSY']);
    $type = OrganizationUnitType::query()->firstOrCreate(['code' => 'SV-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
    UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'create', 'name_en' => 'Good unit', 'proposed_unit_type_id' => $type->id, 'parent_existing_unit_id' => $root->id]);

    $this->actingAs($preparer)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    $this->actingAs($approver)->from('/')->post(route('organization-design.studies.approve', $study))->assertSessionHasErrors('validation');
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);

    $this->actingAs($approver)->post(route('organization-design.studies.return', $study), ['reason' => 'Fix the deactivation'])->assertRedirect();
    $this->actingAs($preparer)->delete(route('organization-design.proposals.destroy', $bad))->assertRedirect();
    $this->actingAs($preparer)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    // Warnings (no responsibilities on the new unit) do not block.
    $this->actingAs($approver)->post(route('organization-design.studies.approve', $study))->assertRedirect();
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::Approved);
});

// 46 ─────────────────────────────────────────────────────────────────────────
test('the study page shows the structural impact of each proposal before it is approved', function (): void {
    $organization = svOrganization('D');
    $preparer = svUser($organization, 'Structure Reviewer');
    $root = svUnit($organization, 'U-ROOT');
    $target = svUnit($organization, 'U-T', $root->id);
    $sub = svUnit($organization, 'U-T-1', $target->id);
    svPosition($organization, $target, 'P-1');
    svPosition($organization, $sub, 'P-2');
    svPosition($organization, $sub, 'P-3', false);
    $study = svStudy($organization, $preparer);
    $deactivate = UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'deactivate', 'existing_unit_id' => $target->id, 'name_en' => 'Unit U-T']);
    $create = UnitDesignProposal::query()->create(['study_id' => $study->id, 'organization_id' => $organization->id, 'proposal_type' => 'create', 'name_en' => 'New', 'parent_existing_unit_id' => $root->id]);

    $this->actingAs($preparer)->get(route('organization-design.studies.show', $study))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Studies/Show')
            ->where("impact.{$deactivate->id}.positions_affected", 3)
            ->where("impact.{$deactivate->id}.active_positions_affected", 2)
            ->where("impact.{$deactivate->id}.child_units_total", 1)
            ->where("impact.{$create->id}.is_new_unit", true)
            ->where("impact.{$create->id}.parent_current_children", 1));
});

// 47 ─────────────────────────────────────────────────────────────────────────
test('the structure page serves the validation report, the unit tree with functional relationships, and the published hierarchy versions', function (): void {
    $organization = svOrganization('E');
    $parentOrg = svOrganization('E-PARENT');
    $viewer = svUser($organization, 'Structure Reviewer');
    $root = svUnit($organization, 'U-ROOT');
    $child = svUnit($organization, 'U-CHILD', $root->id);
    OrganizationUnitRelationship::query()->create(['source_unit_id' => $child->id, 'target_type' => RelationshipTargetType::OrganizationUnit->value, 'target_id' => $root->id, 'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value, 'is_primary' => false, 'status' => RelationshipStatus::Active->value]);
    $published = HierarchyVersion::query()->create(['version_name' => 'SV v1', 'status' => 'published', 'effective_from' => '2025-01-01']);
    $draft = HierarchyVersion::query()->create(['version_name' => 'SV draft', 'status' => 'draft', 'effective_from' => '2026-01-01']);
    foreach ([$published, $draft] as $version) {
        OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $parentOrg->id, 'child_organization_id' => $organization->id, 'relationship_type' => OrganizationRelationshipType::ReportsTo->value, 'effective_from' => '2025-01-01']);
    }

    $this->actingAs($viewer)->get(route('organization-design.structure.index', ['organization_id' => $organization->id]))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Structure/Index')
            ->where('validation.ok', true)
            ->where('units.0.code', 'U-ROOT')
            ->where('units.0.children.0.code', 'U-CHILD')
            ->where('units.0.children.0.relationships.0.relationship_type', 'functional_reporting')
            ->where('units.0.children.0.relationships.0.target.code', 'U-ROOT')
            ->has('hierarchyVersions', 1)
            ->where('hierarchyVersions.0.version_name', 'SV v1')
            ->where('hierarchyVersions.0.parents.0.code', $parentOrg->code));

    $this->actingAs($viewer)->get(route('organization-design.structure.index', ['organization_id' => $parentOrg->id]))->assertForbidden();
});

// 48 ─────────────────────────────────────────────────────────────────────────
test('findings carry a severity, a code, a localized message and the subject, and the summary counts them', function (): void {
    $organization = svOrganization('F');
    $root = svUnit($organization, 'U-ROOT');
    svUnit($organization, 'U-EMPTY', $root->id);
    svPosition($organization, $root, 'P-1');
    svFunction($organization, 'F-1');
    UnitResponsibility::query()->create(['organization_id' => $organization->id, 'organization_unit_id' => $root->id, 'object_type' => 'function', 'object_id' => OrganizationFunction::query()->where('code', 'F-1')->value('id'), 'responsibility_type_id' => ResponsibilityType::query()->where('code', 'RESPONSIBLE')->value('id')]);

    $report = app(OrganizationDesignValidationService::class)->validateStructure($organization->id);

    expect($report['summary'])->toMatchArray(['errors' => 0])
        ->and($report['summary']['warnings'])->toBeGreaterThanOrEqual(1)
        ->and($report['ok'])->toBeTrue();
    $finding = collect($report['findings'])->firstWhere('code', 'unit_without_positions');
    expect($finding['severity'])->toBe('warning')
        ->and($finding['message'])->toBeString()->not->toBe('organization-design.validation.unit_without_positions')
        ->and($finding['subject']['type'])->toBe('unit')
        ->and($finding['subject']['code'])->toBe('U-EMPTY');
    expect(svCodes($report['findings'], 'warning'))->not->toContain('function_uncovered');
});
