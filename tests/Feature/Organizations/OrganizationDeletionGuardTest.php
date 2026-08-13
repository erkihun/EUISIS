<?php

declare(strict_types=1);

use App\Actions\Organizations\DeleteOrganizationAction;
use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\Organizations\OrganizationDeletionGuard;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A government organization with any history is never truly disposable — the
 * safe alternative is Deactivate/Archive. These tests cover every blocking
 * dependency the guard checks, plus the permission gate and audit trail.
 */
beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.update', 'organizations.delete'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('ODG Manager', 'web')->givePermissionTo(['organizations.view', 'organizations.update', 'organizations.delete']);
    Role::findOrCreate('ODG Viewer', 'web')->givePermissionTo(['organizations.view']);

    $this->orgType = OrganizationType::query()->create([
        'code' => 'ODG-TYPE',
        'name_en' => 'Deletion Guard Test Type',
    ]);
});

function odgOrg(string $code, string $status = 'active'): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->orgType->id,
        'code' => $code,
        'name_en' => 'Org '.$code,
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ]);
}

function odgManager(): User
{
    $user = User::factory()->create();
    $user->assignRole('ODG Manager');

    return $user;
}

function odgViewer(): User
{
    $user = User::factory()->create();
    $user->assignRole('ODG Viewer');

    return $user;
}

function odgHierarchyVersion(string $status): HierarchyVersion
{
    return HierarchyVersion::query()->create([
        'version_name' => 'ODG-'.$status.'-'.uniqid(),
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ]);
}

function odgEdge(HierarchyVersion $version, Organization $parent, Organization $child): OrganizationEdge
{
    return OrganizationEdge::query()->create([
        'hierarchy_version_id' => $version->id,
        'parent_organization_id' => $parent->id,
        'child_organization_id' => $child->id,
        'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
        'effective_from' => now()->toDateString(),
    ]);
}

function odgEmployeeAssignment(Organization $organization): EmployeeAssignment
{
    $employee = Employee::query()->create([
        'employee_number' => 'ODG-EMP-'.uniqid(),
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'full_name' => 'Test Employee',
        'status' => EmployeeStatus::Active->value,
    ]);

    return EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
}

// ── Permission gate ───────────────────────────────────────────────────────

it('user without organizations.delete cannot delete an organization', function (): void {
    $org = odgOrg('ODG-001');

    $this->actingAs(odgViewer())
        ->delete(route('organizations.destroy', $org))
        ->assertForbidden();

    expect($org->fresh()->trashed())->toBeFalse();
});

it('hides the delete action from the show page when the user lacks permission', function (): void {
    $org = odgOrg('ODG-002');

    $this->actingAs(odgViewer())
        ->get(route('organizations.show', $org))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.delete', false)
        );
});

// ── Happy path ────────────────────────────────────────────────────────────

it('user with permission can delete an unused draft-only organization', function (): void {
    $org = odgOrg('ODG-003', 'draft');

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertRedirect(route('organizations.index'));

    $fresh = Organization::withTrashed()->find($org->id);
    expect($fresh->trashed())->toBeTrue()
        ->and($fresh->deleted_by)->not->toBeNull();
});

it('writes an audit log entry when an organization is deleted', function (): void {
    $org = odgOrg('ODG-004');
    $actor = odgManager();

    $this->actingAs($actor)->delete(route('organizations.destroy', $org));

    $log = AuditLog::query()
        ->where('auditable_id', $org->id)
        ->where('event_type', 'organization_deleted')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBe($actor->id);
});

it('does not treat an organization audit log as a deletion dependency', function (): void {
    $org = odgOrg('ODG-AUDIT-ONLY');
    AuditLog::query()->create([
        'event_type' => 'organization_created',
        'auditable_type' => Organization::class,
        'auditable_id' => $org->id,
        'organization_id' => $org->id,
    ]);

    expect(app(OrganizationDeletionGuard::class)->reasons($org))->toBe([]);

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertRedirect(route('organizations.index'));
});

it('shows no deletion blockers on the show page for an unused organization', function (): void {
    $org = odgOrg('ODG-005');

    $this->actingAs(odgManager())
        ->get(route('organizations.show', $org))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.delete', true)
            ->where('deletionBlockers', [])
        );
});

// ── Index page: per-row actions + deletion blockers ───────────────────────

it('carries per-row permissions and deletion blockers on the paginated organization list', function (): void {
    $blocked = odgOrg('ODG-019');
    odgEmployeeAssignment($blocked);
    $clean = odgOrg('ODG-020');

    $this->actingAs(odgManager())
        ->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations.data', 2)
            ->where('organizations.data.0.can.delete', true)
            ->where('organizations.data.0.can.archive', true)
            ->where('organizations.data.0.deletion_blockers', ['hasEmployeeAssignments'])
            ->where('organizations.data.1.deletion_blockers', [])
        );

    expect($blocked->code)->toBe('ODG-019')->and($clean->code)->toBe('ODG-020');
});

it('index page rows for a viewer without permission have every action disabled', function (): void {
    $org = odgOrg('ODG-021');

    $this->actingAs(odgViewer())
        ->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizations.data.0.can.delete', false)
            ->where('organizations.data.0.can.archive', false)
            ->where('organizations.data.0.can.deactivate', false)
            ->where('organizations.data.0.can.update', false)
        );

    expect($org->code)->toBe('ODG-021');
});

// ── Blocking dependency: published hierarchy ─────────────────────────────

it('cannot delete an organization used in a published hierarchy version', function (): void {
    $parent = odgOrg('ODG-P1');
    $org = odgOrg('ODG-006');
    $version = odgHierarchyVersion(HierarchyVersionStatus::Published->value);
    odgEdge($version, $parent, $org);

    expect(app(OrganizationDeletionGuard::class)->reasons($org))
        ->toBe(['usedInPublishedHierarchy']);

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertForbidden();

    expect($org->fresh()->trashed())->toBeFalse();
});

it('allows deleting an organization used only in a draft hierarchy version', function (): void {
    $parent = odgOrg('ODG-P2');
    $org = odgOrg('ODG-007');
    $version = odgHierarchyVersion(HierarchyVersionStatus::Draft->value);
    odgEdge($version, $parent, $org);

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertRedirect(route('organizations.index'));

    expect($org->fresh()->trashed())->toBeTrue();
});

// ── Blocking dependency: active children ──────────────────────────────────

it('cannot delete an organization with an active child organization', function (): void {
    $org = odgOrg('ODG-008');
    $child = odgOrg('ODG-008-C', 'active');
    $version = odgHierarchyVersion(HierarchyVersionStatus::Draft->value);
    odgEdge($version, $org, $child);

    expect(app(OrganizationDeletionGuard::class)->reasons($org))
        ->toBe(['hasChildOrganizations']);

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertForbidden();
});

it('allows deleting an organization whose only child is inactive', function (): void {
    $org = odgOrg('ODG-009');
    $child = odgOrg('ODG-009-C', 'inactive');
    $version = odgHierarchyVersion(HierarchyVersionStatus::Draft->value);
    odgEdge($version, $org, $child);

    expect(app(OrganizationDeletionGuard::class)->canBeDeletedSafely($org))->toBeTrue();
});

// ── Blocking dependency: organization units ───────────────────────────────

it('cannot delete an organization with organization units', function (): void {
    $org = odgOrg('ODG-010');
    OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'ODG-UNIT',
        'name_en' => 'Test Unit',
        'status' => 'active',
    ]);

    expect(app(OrganizationDeletionGuard::class)->reasons($org))
        ->toBe(['hasOrganizationUnits']);

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertForbidden();
});

// ── Blocking dependency: positions ────────────────────────────────────────

it('cannot delete an organization with positions', function (): void {
    $org = odgOrg('ODG-011');
    Position::query()->create([
        'organization_id' => $org->id,
        'job_position_code' => 'ODG-POS-001',
        'title_en' => 'Test Position',
        'is_active' => true,
        'effective_from' => now()->toDateString(),
    ]);

    expect(app(OrganizationDeletionGuard::class)->reasons($org))
        ->toBe(['hasPositions']);

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertForbidden();
});

// ── Blocking dependency: employee assignments ─────────────────────────────

it('cannot delete an organization with employee assignments', function (): void {
    $org = odgOrg('ODG-012');
    odgEmployeeAssignment($org);

    expect(app(OrganizationDeletionGuard::class)->reasons($org))
        ->toBe(['hasEmployeeAssignments']);

    $this->actingAs(odgManager())
        ->delete(route('organizations.destroy', $org))
        ->assertForbidden();
});

// ── UI: reasons surfaced when permission is present but blocked ──────────

it('surfaces every blocking reason on the show page without hiding the delete action', function (): void {
    $org = odgOrg('ODG-013');
    OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'ODG-UNIT-2',
        'name_en' => 'Test Unit 2',
        'status' => 'active',
    ]);
    odgEmployeeAssignment($org);

    $this->actingAs(odgManager())
        ->get(route('organizations.show', $org))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.delete', true)
            ->where('deletionBlockers', ['hasOrganizationUnits', 'hasEmployeeAssignments'])
        );
});

// ── Race-condition safety: the Action re-checks inside its own transaction ─

it('the delete action itself rejects a dependency that appears after the fact', function (): void {
    $org = odgOrg('ODG-014');
    $actor = odgManager();

    // A dependency is created deliberately without going through the
    // policy-guarded HTTP flow — simulating one appearing mid-flight.
    odgEmployeeAssignment($org);

    expect(fn () => app(DeleteOrganizationAction::class)->execute($org->fresh(), $actor))
        ->toThrow(ValidationException::class);

    expect($org->fresh()->trashed())->toBeFalse();
});

// ── Safer alternatives: Archive / Deactivate ──────────────────────────────

it('archiving sets status to archived and writes an audit log', function (): void {
    $org = odgOrg('ODG-015');
    $actor = odgManager();

    $this->actingAs($actor)
        ->delete(route('organizations.archive', $org))
        ->assertRedirect(route('organizations.index'));

    expect($org->fresh()->status)->toBe(OrganizationStatus::Archived);

    expect(AuditLog::query()->where('auditable_id', $org->id)->where('event_type', 'organization_archived')->exists())
        ->toBeTrue();
});

it('archiving works even when the organization has dependencies that block deletion', function (): void {
    $org = odgOrg('ODG-016');
    odgEmployeeAssignment($org);

    $this->actingAs(odgManager())
        ->delete(route('organizations.archive', $org))
        ->assertRedirect(route('organizations.index'));

    expect($org->fresh()->status)->toBe(OrganizationStatus::Archived);
});

it('deactivating sets status to inactive and writes an audit log', function (): void {
    $org = odgOrg('ODG-017');
    $actor = odgManager();

    $this->actingAs($actor)
        ->patch(route('organizations.deactivate', $org))
        ->assertRedirect(route('organizations.show', $org));

    expect($org->fresh()->status)->toBe(OrganizationStatus::Inactive);

    expect(AuditLog::query()->where('auditable_id', $org->id)->where('event_type', 'organization_deactivated')->exists())
        ->toBeTrue();
});

it('user without organizations.update cannot archive or deactivate an organization', function (): void {
    $org = odgOrg('ODG-018');
    $viewer = odgViewer();

    $this->actingAs($viewer)->delete(route('organizations.archive', $org))->assertForbidden();
    $this->actingAs($viewer)->patch(route('organizations.deactivate', $org))->assertForbidden();
});

// ── Published vs draft vs archived hierarchy (regression for the wrong block) ─

it('does not report used_in_published_hierarchy for a draft-only organization', function (): void {
    $parent = odgOrg('ODG-DRAFT-P');
    $org = odgOrg('ODG-DRAFT-C');
    odgEdge(odgHierarchyVersion(HierarchyVersionStatus::Draft->value), $parent, $org);

    $guard = app(OrganizationDeletionGuard::class);

    expect($guard->reasons($org))->not->toContain('usedInPublishedHierarchy')
        ->and($guard->debugCounts($org)['published_hierarchy_edges'])->toBe(0)
        ->and($guard->debugCounts($org)['draft_hierarchy_edges'])->toBe(1)
        // draft-only child has no other dependency → fully deletable
        ->and($guard->canBeDeletedSafely($org))->toBeTrue();
});

it('does not report used_in_published_hierarchy for an archived-hierarchy organization', function (): void {
    $parent = odgOrg('ODG-ARCH-P');
    $org = odgOrg('ODG-ARCH-C');
    odgEdge(odgHierarchyVersion(HierarchyVersionStatus::Archived->value), $parent, $org);

    $guard = app(OrganizationDeletionGuard::class);

    expect($guard->reasons($org))->not->toContain('usedInPublishedHierarchy')
        ->and($guard->debugCounts($org)['archived_hierarchy_edges'])->toBe(1)
        ->and($guard->debugCounts($org)['published_hierarchy_edges'])->toBe(0)
        ->and($guard->canBeDeletedSafely($org))->toBeTrue();
});

it('does not report used_in_published_hierarchy when no published version exists at all', function (): void {
    $org = odgOrg('ODG-NOPUB');
    // Draft version present, but nothing published.
    odgEdge(odgHierarchyVersion(HierarchyVersionStatus::Draft->value), odgOrg('ODG-NOPUB-P'), $org);

    expect(app(OrganizationDeletionGuard::class)->reasons($org))
        ->not->toContain('usedInPublishedHierarchy');
});

it('recomputes the reason when a hierarchy version status changes (no stale block)', function (): void {
    $parent = odgOrg('ODG-STALE-P');
    $org = odgOrg('ODG-STALE-C');
    $version = odgHierarchyVersion(HierarchyVersionStatus::Published->value);
    odgEdge($version, $parent, $org);

    $guard = app(OrganizationDeletionGuard::class);
    expect($guard->reasons($org))->toContain('usedInPublishedHierarchy');

    // Unpublish (published → draft): the published-hierarchy block must clear.
    $version->update(['status' => HierarchyVersionStatus::Draft->value]);

    expect($guard->reasons($org))->not->toContain('usedInPublishedHierarchy')
        ->and($guard->canBeDeletedSafely($org))->toBeTrue();
});

// ── Frontend reasons match backend guard ─────────────────────────────────────

it('sends the same deletion_blockers to the frontend as the backend guard computes', function (): void {
    $org = odgOrg('ODG-MATCH');
    OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'ODG-MATCH-U',
        'name_en' => 'Match Unit',
        'status' => 'active',
    ]);

    $guardReasons = app(OrganizationDeletionGuard::class)->reasons($org->fresh());

    // Index list prop
    $this->actingAs(odgManager())
        ->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizations.data.0.deletion_blockers', $guardReasons)
        );

    // Show page prop
    $this->actingAs(odgManager())
        ->get(route('organizations.show', $org))
        ->assertInertia(fn (Assert $page) => $page
            ->where('deletionBlockers', $guardReasons)
        );
});

// ── Index tree resilience: soft-deleted published root must not hide the tree ─

it('still builds the published hierarchy tree when its root org is soft-deleted', function (): void {
    // Root → child, both in a published version; then the ROOT is soft-deleted.
    $root = odgOrg('ODG-ROOT');
    $child = odgOrg('ODG-ROOT-C');
    $version = odgHierarchyVersion(HierarchyVersionStatus::Published->value);
    odgEdge($version, $root, $child);

    $root->delete(); // soft-delete the published root

    // The child must still surface as a (promoted) root, rather than the whole
    // tree collapsing to empty and falsely reading as "no published hierarchy".
    $tree = app(OrganizationScopeService::class)->buildFlatTreeForIndex($version->fresh(), null);
    $codes = collect($tree)->pluck('code');

    expect($codes)->toContain('ODG-ROOT-C')
        ->and($codes)->not->toContain('ODG-ROOT'); // the soft-deleted root itself is not shown
});

it('does not include archived organizations in the published hierarchy tree', function (): void {
    $root = odgOrg('ODG-ARCH-ROOT', OrganizationStatus::Archived->value);
    $child = odgOrg('ODG-ARCH-CHILD');
    $version = odgHierarchyVersion(HierarchyVersionStatus::Published->value);
    odgEdge($version, $root, $child);

    $tree = app(OrganizationScopeService::class)->buildFlatTreeForIndex($version->fresh(), null);
    $codes = collect($tree)->pluck('code');

    expect($codes)->toContain('ODG-ARCH-CHILD')
        ->and($codes)->not->toContain('ODG-ARCH-ROOT')
        ->and($tree[0]['parent_id'])->toBeNull();
});
