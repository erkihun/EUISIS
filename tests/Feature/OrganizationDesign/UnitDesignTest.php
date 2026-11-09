<?php

declare(strict_types=1);

use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\InstitutionMandate;
use App\Models\LegalInstrumentType;
use App\Models\Organization;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationFunction;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\ResponsibilityType;
use App\Models\Service;
use App\Models\UnitDesignProposal;
use App\Models\UnitResponsibility;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Organization Design & Workforce Planning, phase 5: design studies, unit
 * design proposals, responsibility mapping, and the hand-over of accepted
 * proposals to the organizational change-request pipeline (D-1 = b).
 * Brief §35–39, 112–113 — tests 35–42 (continuing the numbering).
 */

beforeEach(function (): void {
    foreach ([
        'organization-design.viewAny', 'organization-design.view', 'organization-design.create', 'organization-design.update', 'organization-design.review', 'organization-design.approve',
        'responsibility-types.manage', 'organizational-change-requests.create', 'organizational-change-requests.view',
        'organization-units.request_create', 'organization-units.request_update', 'organization-units.request_move', 'organization-units.request_deactivate',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web');
    Role::findOrCreate('Structure Designer', 'web')->syncPermissions([
        'organization-design.viewAny', 'organization-design.view', 'organization-design.create', 'organization-design.update', 'organization-design.review',
        'organizational-change-requests.create', 'organizational-change-requests.view', 'organization-units.request_create', 'organization-units.request_update', 'organization-units.request_move', 'organization-units.request_deactivate',
    ]);
    Role::findOrCreate('Structure Approver', 'web')->syncPermissions(['organization-design.viewAny', 'organization-design.view', 'organization-design.review', 'organization-design.approve']);
    Role::findOrCreate('Responsibility Steward', 'web')->syncPermissions(['responsibility-types.manage']);
    foreach ([['RESPONSIBLE', 'Responsible', false, true], ['ACCOUNTABLE', 'Accountable', true, true], ['CONSULTED', 'Consulted', false, false], ['SUPPORTING', 'Supporting', false, false]] as [$code, $name, $exclusive, $covers]) {
        ResponsibilityType::query()->firstOrCreate(['code' => $code], ['name_en' => $name, 'is_exclusive' => $exclusive, 'covers_object' => $covers, 'is_active' => true, 'sort_order' => 0]);
    }
});

function udOrganization(string $suffix): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'UD-BUREAU'], ['name_en' => 'UD Bureau', 'is_demo' => false]);

    return Organization::query()->create([
        'organization_type_id' => $type->id, 'code' => 'UD-'.$suffix.'-'.uniqid(), 'name_en' => 'UD Org '.$suffix,
        'status' => OrganizationStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_demo' => false,
    ]);
}

function udDesignerFor(Organization $organization, string $role = 'Structure Designer'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    UserOrganizationScope::query()->create([
        'user_id' => $user->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self,
        'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
    ]);

    return $user->fresh();
}

function udUnitType(): OrganizationUnitType
{
    return OrganizationUnitType::query()->firstOrCreate(['code' => 'UD-DIR'], ['name_en' => 'Directorate', 'is_active' => true]);
}

function udUnit(Organization $organization, string $code, ?string $parentId = null): OrganizationUnit
{
    return OrganizationUnit::query()->create(['organization_id' => $organization->id, 'parent_unit_id' => $parentId, 'organization_unit_type_id' => udUnitType()->id, 'code' => $code, 'name_en' => 'Unit '.$code, 'unit_type' => 'directorate', 'status' => 'active']);
}

function udFunction(Organization $organization, string $code): OrganizationFunction
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

function udService(Organization $organization, string $code): Service
{
    return Service::query()->create(['organization_id' => $organization->id, 'lineage_key' => (string) Str::uuid(), 'version_no' => 1, 'code' => $code, 'name_en' => 'Service '.$code, 'effective_from' => '2021-01-01', 'status' => OrganizationDesignStatus::Published]);
}

/** @return array<string, mixed> */
function udStudyPayload(Organization $organization, array $overrides = []): array
{
    return ['organization_id' => $organization->id, 'title_en' => 'Restructuring study', 'title_am' => 'የመዋቅር ጥናት', 'study_type' => 'restructure', 'reason' => 'New mandate', 'target_effective_date' => '2027-01-01', ...$overrides];
}

function udStudy(Organization $organization, User $designer, array $overrides = []): OrganizationDesignStudy
{
    test()->actingAs($designer)->post(route('organization-design.studies.store'), udStudyPayload($organization, $overrides))->assertRedirect();

    return OrganizationDesignStudy::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
}

function udProposal(User $designer, OrganizationDesignStudy $study, array $payload): UnitDesignProposal
{
    test()->actingAs($designer)->post(route('organization-design.studies.proposals.store', $study), ['proposal_type' => 'create', 'name_en' => 'New unit', 'proposed_unit_type_id' => udUnitType()->id, ...$payload])->assertRedirect();

    return UnitDesignProposal::query()->where('study_id', $study->id)->latest('id')->firstOrFail();
}

function udType(string $code): ResponsibilityType
{
    return ResponsibilityType::query()->where('code', $code)->firstOrFail();
}

// 35 ─────────────────────────────────────────────────────────────────────────
test('a design study is numbered, scoped to its organization and starts as a draft', function (): void {
    $organization = udOrganization('A');
    $other = udOrganization('A2');
    $designer = udDesignerFor($organization);

    $this->actingAs($designer)->post(route('organization-design.studies.store'), udStudyPayload($other))->assertForbidden();
    $this->actingAs($designer)->from('/')->post(route('organization-design.studies.store'), udStudyPayload($organization, ['study_type' => 'whatever']))->assertSessionHasErrors('study_type');

    $study = udStudy($organization, $designer);
    expect($study->status)->toBe(OrganizationDesignStatus::Draft)
        ->and($study->reference_number)->toMatch('/^ODS-\d{4}-\d{4}$/')
        ->and($study->prepared_by)->toBe($designer->id);
    $second = udStudy($organization, $designer, ['title_en' => 'Second']);
    expect($second->reference_number)->not->toBe($study->reference_number);

    $this->actingAs($designer)->get(route('organization-design.studies.index'))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Studies/Index')->where('studies.total', 2));
    $this->actingAs(udDesignerFor($other))->get(route('organization-design.studies.show', $study))->assertForbidden();
});

// 36 ─────────────────────────────────────────────────────────────────────────
test('unit proposals create, change, move or deactivate units; parents stay inside the study or the organization and never cycle', function (): void {
    $organization = udOrganization('B');
    $other = udOrganization('B2');
    $designer = udDesignerFor($organization);
    $study = udStudy($organization, $designer);
    $existing = udUnit($organization, 'U-1');
    $foreign = udUnit($other, 'U-X');

    // An existing unit on a change proposal must be the organization's own.
    $this->actingAs($designer)->from('/')->post(route('organization-design.studies.proposals.store', $study), ['proposal_type' => 'update', 'existing_unit_id' => $foreign->id, 'name_en' => 'x'])
        ->assertSessionHasErrors('existing_unit_id');
    $this->actingAs($designer)->from('/')->post(route('organization-design.studies.proposals.store', $study), ['proposal_type' => 'move', 'existing_unit_id' => $existing->id])
        ->assertSessionHasErrors('parent_existing_unit_id');

    $root = udProposal($designer, $study, ['name_en' => 'Human Resource Directorate', 'name_am' => 'የሰው ኃይል ዳይሬክቶሬት', 'parent_existing_unit_id' => $existing->id]);
    $child = udProposal($designer, $study, ['name_en' => 'Recruitment Team', 'parent_proposed_unit_id' => $root->id]);
    $grandchild = udProposal($designer, $study, ['name_en' => 'Screening Desk', 'parent_proposed_unit_id' => $child->id]);
    expect($root->proposal_type)->toBe('create')->and($child->parent_proposed_unit_id)->toBe($root->id)->and($root->status)->toBe('proposed');

    $this->actingAs($designer)->from('/')->put(route('organization-design.proposals.update', $root), ['parent_proposed_unit_id' => $grandchild->id])
        ->assertSessionHasErrors('parent_proposed_unit_id');
    $this->actingAs($designer)->put(route('organization-design.proposals.update', $root), ['design_rationale' => 'Span of control 1:6'])->assertRedirect();
    expect($root->fresh()->design_rationale)->toBe('Span of control 1:6');

    $deactivate = udProposal($designer, $study, ['proposal_type' => 'deactivate', 'existing_unit_id' => $existing->id, 'name_en' => 'Unit U-1']);
    expect($deactivate->existing_unit_id)->toBe($existing->id);

    // A parent with children cannot be removed; the leaf can.
    $this->actingAs($designer)->from('/')->delete(route('organization-design.proposals.destroy', $root))->assertSessionHasErrors('proposal');
    $this->actingAs($designer)->delete(route('organization-design.proposals.destroy', $grandchild))->assertRedirect();
    expect(UnitDesignProposal::query()->where('study_id', $study->id)->count())->toBe(3);

    $this->actingAs($designer)->get(route('organization-design.studies.show', $study))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Studies/Show')->where('proposals.0.children.0.name_en', 'Recruitment Team'));
});

// 37 ─────────────────────────────────────────────────────────────────────────
test('responsibilities map functions, services and processes to units or proposals by configurable type, exclusive types once per object', function (): void {
    $organization = udOrganization('C');
    $other = udOrganization('C2');
    $designer = udDesignerFor($organization);
    $study = udStudy($organization, $designer);
    $unit = udUnit($organization, 'U-1');
    $proposal = udProposal($designer, $study, ['name_en' => 'Proposed unit']);
    $f1 = udFunction($organization, 'F-1');
    $s1 = udService($organization, 'SRV-1');
    $foreignFunction = udFunction($other, 'F-X');
    $responsible = udType('RESPONSIBLE');
    $accountable = udType('ACCOUNTABLE');

    $unitUrl = route('organization-design.responsibilities.sync', ['subjectType' => 'unit', 'subject' => $unit->id]);
    $this->actingAs($designer)->from('/')->put($unitUrl, ['items' => [['object_type' => 'function', 'object_id' => $foreignFunction->id, 'responsibility_type_id' => $responsible->id]]])
        ->assertSessionHasErrors('items.0.object_id');
    $this->actingAs($designer)->from('/')->put($unitUrl, ['items' => [['object_type' => 'position', 'object_id' => $f1->id, 'responsibility_type_id' => $responsible->id]]])
        ->assertSessionHasErrors('items.0.object_type');
    $this->actingAs($designer)->put($unitUrl, ['items' => [
        ['object_type' => 'function', 'object_id' => $f1->id, 'responsibility_type_id' => $accountable->id],
        ['object_type' => 'service', 'object_id' => $s1->id, 'responsibility_type_id' => $responsible->id, 'notes' => 'Front desk'],
    ]])->assertRedirect();
    expect(UnitResponsibility::query()->where('organization_unit_id', $unit->id)->count())->toBe(2);

    // The proposal may be responsible for the same function; it may not be accountable too (exclusive).
    $proposalUrl = route('organization-design.responsibilities.sync', ['subjectType' => 'proposal', 'subject' => $proposal->id]);
    $this->actingAs($designer)->from('/')->put($proposalUrl, ['items' => [['object_type' => 'function', 'object_id' => $f1->id, 'responsibility_type_id' => $accountable->id]]])
        ->assertSessionHasErrors('items.0.responsibility_type_id');
    $this->actingAs($designer)->put($proposalUrl, ['items' => [
        ['object_type' => 'function', 'object_id' => $f1->id, 'responsibility_type_id' => $responsible->id],
        ['object_type' => 'function', 'object_id' => $f1->id, 'responsibility_type_id' => $responsible->id],
    ]])->assertSessionHasErrors('items.1.responsibility_type_id');
    $this->actingAs($designer)->put($proposalUrl, ['items' => [['object_type' => 'function', 'object_id' => $f1->id, 'responsibility_type_id' => $responsible->id]]])->assertRedirect();
    $row = UnitResponsibility::query()->where('unit_design_proposal_id', $proposal->id)->firstOrFail();
    expect($row->organization_unit_id)->toBeNull()->and($row->object_id)->toBe($f1->id);

    // Types are a city-level catalog.
    $this->actingAs($designer)->post(route('organization-design.responsibility-types.store'), ['code' => 'INFORMED', 'name_en' => 'Informed'])->assertForbidden();
    $steward = User::factory()->create();
    $steward->assignRole('Responsibility Steward');
    $this->actingAs($steward)->post(route('organization-design.responsibility-types.store'), ['code' => 'INFORMED', 'name_en' => 'Informed', 'is_exclusive' => false])->assertRedirect();
    expect(ResponsibilityType::query()->where('code', 'INFORMED')->exists())->toBeTrue();
});

// 38 ─────────────────────────────────────────────────────────────────────────
test('a study goes draft → under review → approved with maker ≠ checker, and approved proposals freeze', function (): void {
    $organization = udOrganization('D');
    $designer = udDesignerFor($organization);
    $approver = udDesignerFor($organization, 'Structure Approver');
    $study = udStudy($organization, $designer);
    $proposal = udProposal($designer, $study, ['name_en' => 'Proposed unit']);

    $this->actingAs($approver)->from('/')->post(route('organization-design.studies.approve', $study))->assertSessionHasErrors('status');
    $this->actingAs($designer)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::UnderReview);

    $this->actingAs($designer)->post(route('organization-design.studies.approve', $study))->assertForbidden();
    $selfApprover = udDesignerFor($organization, 'Structure Approver');
    $selfApprover->assignRole('Structure Designer');
    $own = udStudy($organization, $selfApprover, ['title_en' => 'Own']);
    $this->actingAs($selfApprover)->post(route('organization-design.studies.submit', $own))->assertRedirect();
    $this->actingAs($selfApprover)->from('/')->post(route('organization-design.studies.approve', $own))->assertSessionHasErrors('status');

    $this->actingAs($approver)->post(route('organization-design.studies.return', $study), ['reason' => 'Add rationale'])->assertRedirect();
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::Draft);
    $this->actingAs($designer)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.studies.approve', $study))->assertRedirect();
    expect($study->fresh()->status)->toBe(OrganizationDesignStatus::Approved)->and($study->fresh()->approved_by)->toBe($approver->id);

    $this->actingAs($designer)->from('/')->put(route('organization-design.proposals.update', $proposal), ['name_en' => 'Late'])->assertSessionHasErrors('status');
    $this->actingAs($designer)->from('/')->post(route('organization-design.studies.proposals.store', $study), ['proposal_type' => 'create', 'name_en' => 'Late', 'proposed_unit_type_id' => udUnitType()->id])->assertSessionHasErrors('status');
    expect($proposal->fresh()->name_en)->toBe('Proposed unit');
});

// 39 ─────────────────────────────────────────────────────────────────────────
test('an approved study hands each proposal to the change-request pipeline exactly once', function (): void {
    $organization = udOrganization('E');
    $designer = udDesignerFor($organization);
    $approver = udDesignerFor($organization, 'Structure Approver');
    $study = udStudy($organization, $designer);
    $existing = udUnit($organization, 'U-1');
    $root = udProposal($designer, $study, ['name_en' => 'HR Directorate', 'parent_existing_unit_id' => $existing->id, 'effective_from' => '2027-01-01']);
    $child = udProposal($designer, $study, ['name_en' => 'Recruitment Team', 'parent_proposed_unit_id' => $root->id]);
    $rename = udProposal($designer, $study, ['proposal_type' => 'update', 'existing_unit_id' => $existing->id, 'name_en' => 'Unit U-1 renamed']);
    $deactivate = udProposal($designer, $study, ['proposal_type' => 'deactivate', 'existing_unit_id' => udUnit($organization, 'U-2')->id, 'name_en' => 'Unit U-2', 'effective_to' => '2027-06-30']);

    $this->actingAs($designer)->from('/')->post(route('organization-design.studies.generate', $study))->assertSessionHasErrors('status');
    $this->actingAs($designer)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.studies.approve', $study))->assertRedirect();

    $this->actingAs($designer)->post(route('organization-design.studies.generate', $study))->assertRedirect();
    $requests = OrganizationalChangeRequest::query()->where('organization_id', $organization->id)->get();
    expect($requests)->toHaveCount(3)
        ->and($requests->map(fn ($r) => $r->request_type instanceof BackedEnum ? $r->request_type->value : $r->request_type)->sort()->values()->all())->toBe(['add_organization_unit', 'deactivate_organization_unit', 'update_organization_unit'])
        ->and($root->fresh()->organizational_change_request_id)->not->toBeNull()
        ->and($root->fresh()->status)->toBe('converted')
        ->and($rename->fresh()->organizational_change_request_id)->not->toBeNull()
        ->and($deactivate->fresh()->organizational_change_request_id)->not->toBeNull()
        // A child of a proposed (not yet existing) unit waits for its parent to be implemented.
        ->and($child->fresh()->organizational_change_request_id)->toBeNull()
        ->and($child->fresh()->status)->toBe('accepted');

    $item = $root->fresh()->changeRequest->items()->firstOrFail();
    expect($item->proposed_data['name_en'])->toBe('HR Directorate')
        ->and($item->proposed_data['parent_unit_id'])->toBe($existing->id)
        ->and($root->fresh()->changeRequest->reason)->toContain($study->reference_number);

    // Running again changes nothing.
    $this->actingAs($designer)->post(route('organization-design.studies.generate', $study))->assertRedirect();
    expect(OrganizationalChangeRequest::query()->where('organization_id', $organization->id)->count())->toBe(3);
});

// 40 ─────────────────────────────────────────────────────────────────────────
test('once a created unit is implemented, its proposal adopts the unit: responsibilities move and dependent children can be handed over', function (): void {
    $organization = udOrganization('F');
    $designer = udDesignerFor($organization);
    $approver = udDesignerFor($organization, 'Structure Approver');
    $study = udStudy($organization, $designer);
    $root = udProposal($designer, $study, ['name_en' => 'HR Directorate']);
    $child = udProposal($designer, $study, ['name_en' => 'Recruitment Team', 'parent_proposed_unit_id' => $root->id]);
    $f1 = udFunction($organization, 'F-1');
    $this->actingAs($designer)->put(route('organization-design.responsibilities.sync', ['subjectType' => 'proposal', 'subject' => $root->id]), ['items' => [['object_type' => 'function', 'object_id' => $f1->id, 'responsibility_type_id' => udType('RESPONSIBLE')->id]]])->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.studies.submit', $study))->assertRedirect();
    $this->actingAs($approver)->post(route('organization-design.studies.approve', $study))->assertRedirect();
    $this->actingAs($designer)->post(route('organization-design.studies.generate', $study))->assertRedirect();

    // Simulate the change-request pipeline implementing the unit.
    $newUnit = udUnit($organization, 'U-NEW');
    $root->fresh()->changeRequest->items()->update(['resulting_entity_id' => $newUnit->id]);

    $this->actingAs($designer)->post(route('organization-design.studies.adopt', $study))->assertRedirect();
    expect($root->fresh()->resulting_unit_id)->toBe($newUnit->id)
        ->and($root->fresh()->status)->toBe('implemented')
        ->and(UnitResponsibility::query()->where('organization_unit_id', $newUnit->id)->where('object_id', $f1->id)->exists())->toBeTrue()
        ->and(UnitResponsibility::query()->where('unit_design_proposal_id', $root->id)->count())->toBe(0);

    // The child can now be handed over, with the implemented unit as its parent.
    $this->actingAs($designer)->post(route('organization-design.studies.generate', $study))->assertRedirect();
    $childRequest = $child->fresh()->changeRequest;
    expect($childRequest)->not->toBeNull()
        ->and($childRequest->items()->firstOrFail()->proposed_data['parent_unit_id'])->toBe($newUnit->id);
});

// 41 ─────────────────────────────────────────────────────────────────────────
test('the responsibility matrix shows coverage gaps: functions, services and processes no unit is responsible for', function (): void {
    $organization = udOrganization('G');
    $designer = udDesignerFor($organization);
    $unit = udUnit($organization, 'U-1');
    $covered = udFunction($organization, 'F-1');
    $uncovered = udFunction($organization, 'F-2');
    $service = udService($organization, 'SRV-1');
    $this->actingAs($designer)->put(route('organization-design.responsibilities.sync', ['subjectType' => 'unit', 'subject' => $unit->id]), ['items' => [
        ['object_type' => 'function', 'object_id' => $covered->id, 'responsibility_type_id' => udType('RESPONSIBLE')->id],
        ['object_type' => 'service', 'object_id' => $service->id, 'responsibility_type_id' => udType('CONSULTED')->id],
    ]])->assertRedirect();

    $this->actingAs($designer)->get(route('organization-design.responsibilities.index', ['organization_id' => $organization->id]))
        ->assertInertia(fn ($page) => $page->component('OrganizationDesign/Responsibilities/Index')
            ->where('gaps.functions.0.code', 'F-2')
            ->where('gaps.services.0.code', 'SRV-1')
            ->where('matrix.0.unit.code', 'U-1')
            ->has('matrix.0.rows', 2));
    expect(OrganizationFunction::query()->whereKey($uncovered->id)->exists())->toBeTrue();
});

// 42 ─────────────────────────────────────────────────────────────────────────
test('a scoped designer cannot read or write another organization\'s study, proposals or unit responsibilities', function (): void {
    $mine = udOrganization('H');
    $theirs = udOrganization('H2');
    $designer = udDesignerFor($mine);
    $foreignDesigner = udDesignerFor($theirs);
    $foreignStudy = udStudy($theirs, $foreignDesigner);
    $foreignProposal = udProposal($foreignDesigner, $foreignStudy, ['name_en' => 'Theirs']);
    $foreignUnit = udUnit($theirs, 'U-X');

    $this->actingAs($designer)->get(route('organization-design.studies.show', $foreignStudy))->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.studies.update', $foreignStudy), ['title_en' => 'x'])->assertForbidden();
    $this->actingAs($designer)->post(route('organization-design.studies.proposals.store', $foreignStudy), ['proposal_type' => 'create', 'name_en' => 'x', 'proposed_unit_type_id' => udUnitType()->id])->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.proposals.update', $foreignProposal), ['name_en' => 'x'])->assertForbidden();
    $this->actingAs($designer)->put(route('organization-design.responsibilities.sync', ['subjectType' => 'unit', 'subject' => $foreignUnit->id]), ['items' => []])->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.responsibilities.index', ['organization_id' => $theirs->id]))->assertForbidden();
    $this->actingAs($designer)->get(route('organization-design.studies.index'))
        ->assertInertia(fn ($page) => $page->where('studies.total', 0));
});
