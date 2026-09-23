<?php

declare(strict_types=1);

use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationalChangeRequestStatus;
use App\Enums\OrganizationalChangeRequestType;
use App\Models\AuditLog;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionMovement;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationalChange\ChangeRequestImpactAnalyzer;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\OrganizationStructure\ApplyApprovedOrganizationalChangeService;
use App\Services\OrganizationStructure\Exceptions\ImplementationBlockedException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Organizational change request workflow
|--------------------------------------------------------------------------
|
| The rule these tests exist to defend:
|
|   A request is permission to ASK for a change. Approval authorises the
|   RESPONSIBLE IMPLEMENTING UNIT to apply it. Approval never turns the
|   requester into an editor of organization units, positions or hierarchy.
|
| Everything below is arranged around separated duties: a requester, a
| reviewer and an implementer, each holding only their own permissions.
*/

// ── Fixtures ────────────────────────────────────────────────────────────────

function ocrPermission(string $name): Permission
{
    return Permission::findOrCreate($name, 'web');
}

/** @param array<int, string> $permissions */
function ocrUserWithPermissions(string $roleName, array $permissions, ?Organization $organization = null): User
{
    $role = Role::findOrCreate($roleName, 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(ocrPermission($permission));
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    if ($organization !== null) {
        UserOrganizationScope::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'scope_type' => 'subtree',
            'is_active' => true,
        ]);
    }

    $user->forgetCachedPermissions();
    app(OrganizationScopeService::class)->clearCache();

    return $user->fresh();
}

const OCR_REQUESTER_PERMS = [
    'organizational-change-requests.view_own',
    'organizational-change-requests.create',
    'organizational-change-requests.update_draft',
    'organizational-change-requests.submit',
    'organizational-change-requests.cancel_own',
    'organizational-change-requests.resubmit',
    'organization-units.request_create',
    'organization-units.request_update',
    'organization-units.request_move',
    'organization-units.request_deactivate',
    'positions.request_create',
    'positions.request_update',
    'positions.request_move',
    'positions.request_increase',
    'positions.request_abolish',
];

const OCR_REVIEWER_PERMS = [
    'organizational-change-requests.view',
    'organizational-change-requests.review',
    'organizational-change-requests.request_correction',
    'organizational-change-requests.approve',
    'organizational-change-requests.reject',
];

const OCR_IMPLEMENTER_PERMS = [
    'organizational-change-requests.view',
    'organizational-change-requests.view_approved',
    'organizational-change-requests.assign_implementation',
    'organizational-change-requests.implement',
    'organizational-change-requests.complete',
];

/**
 * Implementation generates real codes through the normal code-rule pipeline,
 * exactly as the direct create path does, so the rules have to exist here too.
 */
function ocrCodeRule(string $entityType, string $prefix): CodeRule
{
    return CodeRule::query()->create([
        'entity_type' => $entityType,
        'scope_type' => null,
        'scope_id' => null,
        'active_scope_key' => CodeRule::buildActiveScopeKey($entityType),
        'name_en' => $prefix.' Code',
        'prefix' => $prefix,
        'format' => '{PREFIX}-{SEQUENCE_PADDED}',
        'separator' => '-',
        'sequence_length' => 4,
        'next_number' => 1,
        'reset_frequency' => CodeRuleResetFrequency::Never,
        'year_format' => 'Y',
        'is_active' => true,
        'allow_manual_override' => false,
        'require_approval_for_override' => true,
    ]);
}

beforeEach(function (): void {
    ocrCodeRule(CodeRuleEntityType::OrganizationUnit->value, 'OCRU');
    ocrCodeRule(CodeRuleEntityType::Position->value, 'OCRP');

    $type = OrganizationType::query()->create(['code' => 'OCR-TYPE', 'name_en' => 'Change Type']);

    $this->unitType = OrganizationUnitType::query()->create([
        'code' => 'DIRECTORATE',
        'name_en' => 'Directorate',
        'is_active' => true,
    ]);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OCR-ORG',
        'name_en' => 'Change Organization',
        'name_am' => 'የለውጥ ድርጅት',
        'status' => 'active',
    ]);

    $this->otherOrg = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'OCR-OTHER',
        'name_en' => 'Other Organization',
        'status' => 'active',
    ]);

    $this->unit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'OCR-U1',
        'name_en' => 'Administration Sector',
        'unit_type' => 'sector',
        'status' => 'active',
    ]);

    $this->otherUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'OCR-U2',
        'name_en' => 'Corporate Services Sector',
        'unit_type' => 'sector',
        'status' => 'active',
    ]);

    $this->foreignUnit = OrganizationUnit::query()->create([
        'organization_id' => $this->otherOrg->id,
        'code' => 'OCR-U3',
        'name_en' => 'Foreign Sector',
        'unit_type' => 'sector',
        'status' => 'active',
    ]);

    $this->position = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'job_position_code' => 'OCR-P1',
        'title_en' => 'Records Officer',
        'bpr_name' => 'Records Officer BPR',
        'grade_level' => 'IX',
        'is_active' => true,
    ]);

    $this->requester = ocrUserWithPermissions('OCR Requester', OCR_REQUESTER_PERMS, $this->org);
    $this->reviewer = ocrUserWithPermissions('OCR Reviewer', OCR_REVIEWER_PERMS, $this->org);
    $this->implementer = ocrUserWithPermissions('OCR Implementer', OCR_IMPLEMENTER_PERMS, $this->org);
});

/** Create a draft through the HTTP surface, as a requester would. */
function ocrCreateDraft(User $actor, Organization $org, string $type, array $payload, string $reason = 'A properly justified reason for the change.'): OrganizationalChangeRequest
{
    test()->actingAs($actor)->post(route('organizational-change-requests.store'), [
        'organization_id' => $org->id,
        'request_type' => $type,
        'reason' => $reason,
        'payload' => $payload,
    ])->assertRedirect();

    return OrganizationalChangeRequest::query()->latest('created_at')->firstOrFail();
}

/** Drive a request from draft to PENDING_IMPLEMENTATION. */
function ocrApprove(OrganizationalChangeRequest $request, User $requester, User $reviewer): OrganizationalChangeRequest
{
    test()->actingAs($requester)->post(route('organizational-change-requests.submit', $request))->assertRedirect();
    test()->actingAs($reviewer)->post(route('organizational-change-requests.approve', $request), ['comment' => 'Approved.'])->assertRedirect();

    return $request->fresh();
}

// ── 1-6. Requester ──────────────────────────────────────────────────────────

test('1. a scoped organizational user can create an allowed request', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Planning Directorate',
        'organization_unit_type_id' => $this->unitType->id,
        'parent_unit_id' => $this->unit->id,
    ]);

    expect($request->status)->toBe(OrganizationalChangeRequestStatus::Draft)
        ->and($request->request_no)->toStartWith('OCR-')
        ->and($request->requested_by)->toBe($this->requester->id)
        ->and($request->items)->toHaveCount(1);

    // Nothing was written to master data.
    expect(OrganizationUnit::query()->where('name_en', 'Planning Directorate')->exists())->toBeFalse();
});

test('2. a requester cannot raise a request outside their organization scope', function (): void {
    $this->actingAs($this->requester)
        ->post(route('organizational-change-requests.store'), [
            'organization_id' => $this->otherOrg->id,
            'request_type' => 'add_organization_unit',
            'reason' => 'Trying to reach another organization entirely.',
            'payload' => ['name_en' => 'Sneaky Unit', 'organization_unit_type_id' => $this->unitType->id],
        ])
        ->assertSessionHasErrors('organization_id');

    expect(OrganizationalChangeRequest::query()->count())->toBe(0);
});

test('3. a requester can edit their own draft', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'update_organization_unit', [
        'entity_id' => $this->unit->id,
        'name_en' => 'Administration Sector (Revised)',
    ]);

    $this->actingAs($this->requester)
        ->patch(route('organizational-change-requests.update', $request), [
            'reason' => 'An updated and still well justified reason.',
            'payload' => ['entity_id' => $this->unit->id, 'name_en' => 'Administration Sector (Final)'],
        ])
        ->assertRedirect();

    expect($request->fresh()->primaryItem()->proposed_data['name_en'])->toBe('Administration Sector (Final)');
});

test('4. a submitted request cannot be edited except through the correction flow', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'update_organization_unit', [
        'entity_id' => $this->unit->id,
        'name_en' => 'Renamed Sector',
    ]);

    $this->actingAs($this->requester)->post(route('organizational-change-requests.submit', $request))->assertRedirect();

    // Submitted: locked.
    $this->actingAs($this->requester)
        ->patch(route('organizational-change-requests.update', $request), [
            'reason' => 'Trying to change it after submission.',
            'payload' => ['entity_id' => $this->unit->id, 'name_en' => 'Sneaky Rename'],
        ])
        ->assertForbidden();

    // Correction reopens it.
    $this->actingAs($this->reviewer)
        ->post(route('organizational-change-requests.request-correction', $request), ['comment' => 'Correct the proposed name.'])
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->patch(route('organizational-change-requests.update', $request), [
            'reason' => 'Corrected as requested by the reviewer.',
            'payload' => ['entity_id' => $this->unit->id, 'name_en' => 'Properly Renamed Sector'],
        ])
        ->assertRedirect();

    expect($request->fresh()->primaryItem()->proposed_data['name_en'])->toBe('Properly Renamed Sector');
});

test('5. a requester cannot approve their own request without a separate permission', function (): void {
    // Give the requester the ordinary approve permission — still not enough.
    $role = Role::findByName('OCR Requester', 'web');
    $role->givePermissionTo(ocrPermission('organizational-change-requests.approve'));
    $this->requester->forgetCachedPermissions();

    $request = ocrCreateDraft($this->requester, $this->org, 'update_organization_unit', [
        'entity_id' => $this->unit->id,
        'name_en' => 'Self Approved Sector',
    ]);

    $this->actingAs($this->requester)->post(route('organizational-change-requests.submit', $request))->assertRedirect();
    $this->actingAs($this->requester)->post(route('organizational-change-requests.approve', $request))->assertForbidden();

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::Submitted);
});

test('6. approval does not grant the requester any master-data permission', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Newly Approved Unit',
        'organization_unit_type_id' => $this->unitType->id,
    ]);

    $permissionsBefore = $this->requester->getAllPermissions()->pluck('name')->sort()->values()->all();

    ocrApprove($request, $this->requester, $this->reviewer);

    $requester = $this->requester->fresh();
    $requester->forgetCachedPermissions();
    $permissionsAfter = $requester->getAllPermissions()->pluck('name')->sort()->values()->all();

    // The permission set is byte-identical before and after approval.
    expect($permissionsAfter)->toBe($permissionsBefore)
        ->and($requester->can('organization-units.create'))->toBeFalse()
        ->and($requester->can('organization-units.update'))->toBeFalse()
        ->and($requester->can('positions.create'))->toBeFalse()
        ->and($requester->can('positions.update'))->toBeFalse()
        ->and($requester->can('organization-units.manageHierarchy'))->toBeFalse();

    // And no scope row was invented for them either.
    expect(UserOrganizationScope::query()->where('user_id', $requester->id)->count())->toBe(1);
});

// ── 7-11. Review ────────────────────────────────────────────────────────────

test('7. an authorized reviewer can review a request in scope', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'update_organization_unit', [
        'entity_id' => $this->unit->id,
        'name_en' => 'Reviewed Sector',
    ]);

    $this->actingAs($this->requester)->post(route('organizational-change-requests.submit', $request))->assertRedirect();
    $this->actingAs($this->reviewer)->post(route('organizational-change-requests.start-review', $request))->assertRedirect();

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::UnderReview)
        ->and($request->fresh()->reviewed_by)->toBe($this->reviewer->id);
});

test('8. an unauthorized reviewer is denied', function (): void {
    $outsider = ocrUserWithPermissions('OCR Outside Reviewer', OCR_REVIEWER_PERMS, $this->otherOrg);

    $request = ocrCreateDraft($this->requester, $this->org, 'update_organization_unit', [
        'entity_id' => $this->unit->id,
        'name_en' => 'Contested Sector',
    ]);

    $this->actingAs($this->requester)->post(route('organizational-change-requests.submit', $request))->assertRedirect();
    $this->actingAs($outsider)->post(route('organizational-change-requests.approve', $request))->assertForbidden();

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::Submitted);
});

test('9. requesting a correction requires a comment', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'update_organization_unit', [
        'entity_id' => $this->unit->id,
        'name_en' => 'Needs Correcting',
    ]);

    $this->actingAs($this->requester)->post(route('organizational-change-requests.submit', $request))->assertRedirect();

    $this->actingAs($this->reviewer)
        ->post(route('organizational-change-requests.request-correction', $request), ['comment' => '   '])
        ->assertSessionHasErrors('comment');

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::Submitted);
});

test('10. a rejected request cannot be implemented', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'update_organization_unit', [
        'entity_id' => $this->unit->id,
        'name_en' => 'Doomed Sector',
    ]);

    $this->actingAs($this->requester)->post(route('organizational-change-requests.submit', $request))->assertRedirect();
    $this->actingAs($this->reviewer)
        ->post(route('organizational-change-requests.reject', $request), ['comment' => 'Not justified.'])
        ->assertRedirect();

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::Rejected);

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'trying anyway'])
        ->assertForbidden();
});

test('11. an approved request becomes PENDING_IMPLEMENTATION, not IMPLEMENTED', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Pending Unit',
        'organization_unit_type_id' => $this->unitType->id,
    ]);

    $request = ocrApprove($request, $this->requester, $this->reviewer);

    expect($request->status)->toBe(OrganizationalChangeRequestStatus::PendingImplementation)
        ->and($request->approved_by)->toBe($this->reviewer->id)
        ->and($request->approved_at)->not->toBeNull()
        ->and($request->approved_payload_hash)->not->toBeNull()
        ->and($request->implemented_at)->toBeNull();

    // Still nothing in master data.
    expect(OrganizationUnit::query()->where('name_en', 'Pending Unit')->exists())->toBeFalse();
});

// ── 12-20. Implementation ───────────────────────────────────────────────────

test('12. only an authorized implementer can implement', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Implementable Unit',
        'organization_unit_type_id' => $this->unitType->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Applied.'])
        ->assertRedirect();

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::Implemented);
});

test('13. the requester cannot implement their own approved request', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Requester Cannot Apply',
        'organization_unit_type_id' => $this->unitType->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $this->actingAs($this->requester)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Doing it myself.'])
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::PendingImplementation)
        ->and(OrganizationUnit::query()->where('name_en', 'Requester Cannot Apply')->exists())->toBeFalse();
});

test('14. the approved payload is read-only: a post-approval edit blocks implementation', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_position', [
        'organization_unit_id' => $this->unit->id,
        'title_en' => 'Frozen Officer',
        'quantity' => 2,
        'grade_level' => 'X',
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    // Tamper with the stored proposal directly, bypassing the workflow.
    $item = $request->primaryItem();
    $item->forceFill(['proposed_data' => array_merge($item->proposed_data, ['quantity' => 50])])->save();

    expect(fn () => app(ApplyApprovedOrganizationalChangeService::class)
        ->execute($request->fresh(), $this->implementer))
        ->toThrow(ImplementationBlockedException::class);

    expect($request->fresh()->status)->toBe(OrganizationalChangeRequestStatus::ImplementationBlocked)
        ->and(Position::query()->where('title_en', 'Frozen Officer')->count())->toBe(0);
});

test('15. the implementer cannot change approved quantity, grade or unit', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_position', [
        'organization_unit_id' => $this->unit->id,
        'title_en' => 'Exact Officer',
        'quantity' => 2,
        'grade_level' => 'VIII',
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    // Post values the implementer might wish were applied. They are ignored:
    // the endpoint accepts only a note.
    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), [
            'note' => 'Attempting an override.',
            'quantity' => 99,
            'grade_level' => 'I',
            'organization_unit_id' => $this->otherUnit->id,
            'title_en' => 'Overridden Officer',
        ])
        ->assertRedirect();

    $created = Position::query()->where('title_en', 'Exact Officer')->get();

    expect($created)->toHaveCount(2)
        ->and(Position::query()->where('title_en', 'Overridden Officer')->count())->toBe(0);

    foreach ($created as $position) {
        expect($position->grade_level)->toBe('VIII')
            ->and($position->organization_unit_id)->toBe($this->unit->id);
    }
});

test('16. implementation applies exactly the approved values', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Exactly As Approved',
        'name_am' => 'በትክክል እንደጸደቀው',
        'organization_unit_type_id' => $this->unitType->id,
        'parent_unit_id' => $this->unit->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Applied.'])
        ->assertRedirect();

    $unit = OrganizationUnit::query()->where('name_en', 'Exactly As Approved')->first();

    expect($unit)->not->toBeNull()
        ->and($unit->name_am)->toBe('በትክክል እንደጸደቀው')
        ->and($unit->parent_unit_id)->toBe($this->unit->id)
        ->and($unit->organization_id)->toBe($this->org->id)
        ->and($unit->metadata['created_via_change_request'])->toBe($request->fresh()->request_no);
});

test('17. implementation writes master-data history for a position move', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'move_position', [
        'entity_id' => $this->position->id,
        'organization_unit_id' => $this->otherUnit->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Moved.'])
        ->assertRedirect();

    $movement = PositionMovement::query()->where('position_id', $this->position->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->from_organization_unit_id)->toBe($this->unit->id)
        ->and($movement->to_organization_unit_id)->toBe($this->otherUnit->id)
        ->and($movement->moved_by)->toBe($this->implementer->id)
        ->and($this->position->fresh()->organization_unit_id)->toBe($this->otherUnit->id);
});

test('18. implementation writes an audit log', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Audited Unit',
        'organization_unit_type_id' => $this->unitType->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Applied.'])
        ->assertRedirect();

    expect(AuditLog::query()->where('event_type', 'organizational_change_request.implemented')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'organization_unit_created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event_type', 'organizational_change_request.approved')->exists())->toBeTrue();

    // The request's own history is append-only and records each hop.
    $statuses = $request->fresh()->history->pluck('to_status')->all();
    expect($statuses)->toContain('submitted', 'approved', 'pending_implementation', 'implemented');
});

test('19. double implementation is blocked', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Applied Once Only',
        'organization_unit_type_id' => $this->unitType->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $service = app(ApplyApprovedOrganizationalChangeService::class);
    $service->execute($request->fresh(), $this->implementer);

    // The second claim finds no request in a claimable status.
    expect(fn () => $service->execute($request->fresh(), $this->implementer))
        ->toThrow(ValidationException::class);

    expect(OrganizationUnit::query()->where('name_en', 'Applied Once Only')->count())->toBe(1);
});

test('20. an implementation conflict is handled safely and applies nothing', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'move_position', [
        'entity_id' => $this->position->id,
        'organization_unit_id' => $this->otherUnit->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    // The target unit is archived after approval.
    $this->otherUnit->forceFill(['status' => 'archived'])->save();

    expect(fn () => app(ApplyApprovedOrganizationalChangeService::class)
        ->execute($request->fresh(), $this->implementer))
        ->toThrow(ImplementationBlockedException::class);

    $fresh = $request->fresh();

    expect($fresh->status)->toBe(OrganizationalChangeRequestStatus::ImplementationBlocked)
        ->and($fresh->blocked_reasons)->not->toBeEmpty()
        ->and($this->position->fresh()->organization_unit_id)->toBe($this->unit->id)
        ->and(PositionMovement::query()->count())->toBe(0);
});

// ── 21-24. Organization unit rules ──────────────────────────────────────────

test('21. an approved unit-add creates the unit only during implementation', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_organization_unit', [
        'name_en' => 'Deferred Unit',
        'organization_unit_type_id' => $this->unitType->id,
    ]);

    expect(OrganizationUnit::query()->where('name_en', 'Deferred Unit')->exists())->toBeFalse();

    $request = ocrApprove($request, $this->requester, $this->reviewer);
    expect(OrganizationUnit::query()->where('name_en', 'Deferred Unit')->exists())->toBeFalse();

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Applied.'])
        ->assertRedirect();

    expect(OrganizationUnit::query()->where('name_en', 'Deferred Unit')->exists())->toBeTrue();
});

test('22. a hierarchy cycle is rejected at request time', function (): void {
    $child = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'OCR-CHILD',
        'name_en' => 'Child Unit',
        'unit_type' => 'department',
        'status' => 'active',
        'parent_unit_id' => $this->unit->id,
    ]);

    // Moving the parent under its own child would close a cycle.
    $this->actingAs($this->requester)
        ->post(route('organizational-change-requests.store'), [
            'organization_id' => $this->org->id,
            'request_type' => 'move_organization_unit',
            'reason' => 'Attempting to create a cycle in the hierarchy.',
            'payload' => ['entity_id' => $this->unit->id, 'parent_unit_id' => $child->id],
        ])
        ->assertSessionHasErrors('parent_unit_id');

    expect(OrganizationalChangeRequest::query()->count())->toBe(0);
});

test('23. an invalid parent is rejected', function (): void {
    $this->actingAs($this->requester)
        ->post(route('organizational-change-requests.store'), [
            'organization_id' => $this->org->id,
            'request_type' => 'add_organization_unit',
            'reason' => 'Parent that does not exist at all.',
            'payload' => [
                'name_en' => 'Orphan Unit',
                'organization_unit_type_id' => $this->unitType->id,
                'parent_unit_id' => '00000000-0000-0000-0000-000000000000',
            ],
        ])
        ->assertSessionHasErrors('parent_unit_id');
});

test('24. an out-of-scope unit target is rejected even when the organization matches', function (): void {
    // The foreign unit belongs to another organization.
    $this->actingAs($this->requester)
        ->post(route('organizational-change-requests.store'), [
            'organization_id' => $this->org->id,
            'request_type' => 'update_organization_unit',
            'reason' => 'Targeting a unit from another organization.',
            'payload' => ['entity_id' => $this->foreignUnit->id, 'name_en' => 'Hijacked Unit'],
        ])
        ->assertSessionHasErrors('entity_id');
});

// ── 25-28. Position rules ───────────────────────────────────────────────────

test('25. an approved position request creates the correct quantity', function (): void {
    $request = ocrCreateDraft($this->requester, $this->org, 'add_position', [
        'organization_unit_id' => $this->unit->id,
        'title_en' => 'Bulk Officer',
        'quantity' => 3,
        'grade_level' => 'VII',
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Created.'])
        ->assertRedirect();

    $created = Position::query()->where('title_en', 'Bulk Officer')->get();

    expect($created)->toHaveCount(3);
    // Each gets its own distinct generated code.
    expect($created->pluck('job_position_code')->unique())->toHaveCount(3);
});

test('26. abolishing an occupied position is blocked at request time', function (): void {
    $employee = Employee::query()->create([
        'employee_number' => 'OCR-EMP-1',
        'first_name' => 'Occupying',
        'last_name' => 'Employee',
        'full_name' => 'Occupying Employee',
        'status' => 'active',
    ]);

    EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'position_id' => $this->position->id,
        'assignment_status' => 'active',
        'effective_from' => now()->subYear()->toDateString(),
        'is_current' => true,
    ]);

    $this->actingAs($this->requester)
        ->post(route('organizational-change-requests.store'), [
            'organization_id' => $this->org->id,
            'request_type' => 'abolish_position',
            'reason' => 'Abolishing a position that is currently occupied.',
            'payload' => ['entity_id' => $this->position->id],
        ])
        ->assertSessionHasErrors('entity_id');

    expect($this->position->fresh())->not->toBeNull();
});

test('27. a position cannot be moved to another organization', function (): void {
    $this->actingAs($this->requester)
        ->post(route('organizational-change-requests.store'), [
            'organization_id' => $this->org->id,
            'request_type' => 'move_position',
            'reason' => 'Moving a position across organizations.',
            'payload' => ['entity_id' => $this->position->id, 'organization_unit_id' => $this->foreignUnit->id],
        ])
        ->assertSessionHasErrors('organization_unit_id');

    expect($this->position->fresh()->organization_unit_id)->toBe($this->unit->id);
});

test('28. a position code is preserved across an approved move', function (): void {
    $originalCode = $this->position->job_position_code;

    $request = ocrCreateDraft($this->requester, $this->org, 'move_position', [
        'entity_id' => $this->position->id,
        'organization_unit_id' => $this->otherUnit->id,
    ]);
    $request = ocrApprove($request, $this->requester, $this->reviewer);

    $this->actingAs($this->implementer)
        ->post(route('organizational-change-requests.implement', $request), ['note' => 'Moved.'])
        ->assertRedirect();

    expect($this->position->fresh()->job_position_code)->toBe($originalCode);
});

// ── 29-30. Security ─────────────────────────────────────────────────────────

test('29. a request-only user is denied direct master-data CRUD', function (): void {
    // These are the direct routes the requester must never reach, whatever
    // the frontend chooses to render.
    $this->actingAs($this->requester)->get(route('organization-units.create'))->assertForbidden();

    $this->actingAs($this->requester)
        ->post(route('organization-units.store'), [
            'organization_id' => $this->org->id,
            'name_en' => 'Direct Unit',
            'unit_type' => 'department',
        ])
        ->assertForbidden();

    $this->actingAs($this->requester)
        ->patch(route('organization-units.update', $this->unit), ['name_en' => 'Direct Rename'])
        ->assertForbidden();

    $this->actingAs($this->requester)
        ->post(route('positions.store'), [
            'organization_id' => $this->org->id,
            'organization_unit_id' => $this->unit->id,
            'title_en' => 'Direct Position',
        ])
        ->assertForbidden();

    expect($this->unit->fresh()->name_en)->toBe('Administration Sector')
        ->and(OrganizationUnit::query()->where('name_en', 'Direct Unit')->exists())->toBeFalse()
        ->and(Position::query()->where('title_en', 'Direct Position')->exists())->toBeFalse();
});

test('30. IDOR: a request from another organization is not readable by id', function (): void {
    $foreignRequester = ocrUserWithPermissions('OCR Foreign Requester', OCR_REQUESTER_PERMS, $this->otherOrg);

    $foreignRequest = ocrCreateDraft($foreignRequester, $this->otherOrg, 'add_organization_unit', [
        'name_en' => 'Private Foreign Unit',
        'organization_unit_type_id' => $this->unitType->id,
    ]);

    // Guessing the id gets a 403, not the record.
    $this->actingAs($this->requester)
        ->get(route('organizational-change-requests.show', $foreignRequest))
        ->assertForbidden();

    // A reviewer scoped elsewhere is refused too.
    $this->actingAs($this->reviewer)
        ->get(route('organizational-change-requests.show', $foreignRequest))
        ->assertForbidden();

    // And the owner still sees their own.
    $this->actingAs($foreignRequester)
        ->get(route('organizational-change-requests.show', $foreignRequest))
        ->assertOk();
});

// ── 31. Localization ────────────────────────────────────────────────────────

test('31. every status and request type has an English and Amharic label', function (): void {
    foreach (OrganizationalChangeRequestStatus::values() as $status) {
        foreach (['en', 'am'] as $locale) {
            app()->setLocale($locale);
            $key = 'organizational-change-requests.statuses.'.$status;
            expect(__($key))->not->toBe($key);
        }
    }

    foreach (OrganizationalChangeRequestType::values() as $type) {
        foreach (['en', 'am'] as $locale) {
            app()->setLocale($locale);
            $key = 'organizational-change-requests.types.'.$type;
            expect(__($key))->not->toBe($key);
        }
    }

    app()->setLocale('en');
});

// ── 33-34. Impact analysis honesty ──────────────────────────────────────────

test('33. establishment figures are null, not zero, when no establishment records exist', function (): void {
    // Reproduces a real case: a position occupied by 3 employees in an
    // organization that has never adopted establishment records. Reporting
    // "0 approved" beside "3 occupied" was self-contradictory.
    $employees = collect(range(1, 3))->map(function (int $n): Employee {
        $employee = Employee::query()->create([
            'employee_number' => 'OCR-IMP-'.$n,
            'first_name' => 'Holder',
            'last_name' => 'Number'.$n,
            'full_name' => 'Holder Number'.$n,
            'status' => 'active',
        ]);

        EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $this->org->id,
            'organization_unit_id' => $this->unit->id,
            'position_id' => $this->position->id,
            'assignment_status' => 'active',
            'effective_from' => now()->subYear()->toDateString(),
            'is_current' => true,
        ]);

        return $employee;
    });

    expect(PositionEstablishment::query()->count())->toBe(0);

    $impact = app(ChangeRequestImpactAnalyzer::class)
        ->positionImpact((string) $this->position->id, ['additional_quantity' => 2]);

    // Absent data is absent, never a misleading zero.
    expect($impact['has_establishment_data'])->toBeFalse()
        ->and($impact['approved_positions'])->toBeNull()
        ->and($impact['vacant_positions'])->toBeNull();

    // Occupancy is real and is still reported.
    expect($impact['occupied_positions'])->toBe(3)
        ->and($impact['assigned_employees'])->toBe(3);

    // The baseline falls back to live position rows, so the projected total
    // is coherent: 1 existing + 2 requested = 3.
    expect($impact['existing_positions'])->toBe(1)
        ->and($impact['requested_additional_positions'])->toBe(2)
        ->and($impact['expected_total_after_approval'])->toBe(3);

    expect($employees)->toHaveCount(3);
});

test('34. establishment figures are reported when the records do exist', function (): void {
    PositionEstablishment::query()->create([
        'establishment_number' => 'EST-OCR-1',
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'position_id' => $this->position->id,
        'approved_slots' => 5,
        'effective_from' => now()->subYear()->toDateString(),
        'status' => EstablishmentStatus::Approved,
    ]);

    $impact = app(ChangeRequestImpactAnalyzer::class)
        ->positionImpact((string) $this->position->id, ['additional_quantity' => 2]);

    expect($impact['has_establishment_data'])->toBeTrue()
        ->and($impact['approved_positions'])->toBe(5)
        ->and($impact['occupied_positions'])->toBe(0)
        ->and($impact['vacant_positions'])->toBe(5)
        // With a real ceiling the projection uses it, not the row count.
        ->and($impact['expected_total_after_approval'])->toBe(7);
});
