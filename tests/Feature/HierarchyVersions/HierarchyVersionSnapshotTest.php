<?php

declare(strict_types=1);

use App\Actions\Organizations\ArchiveHierarchyVersionAction;
use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Hierarchy\HierarchyTreeService;
use App\Services\Hierarchy\HierarchyVersionSnapshotService;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['hierarchy-versions.viewAny', 'hierarchy-versions.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->syncPermissions(['hierarchy-versions.viewAny', 'hierarchy-versions.view']);
    Role::findOrCreate('Scoped Viewer', 'web')->syncPermissions(['hierarchy-versions.viewAny', 'hierarchy-versions.view']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
    $this->type = OrganizationType::query()->create(['code' => 'SNAP', 'name_en' => 'Snap Type', 'name_am' => 'ዓይነት']);
    $this->parent = snapOrganization('P-1', 'Parent');
    $this->child = snapOrganization('C-1', 'Child');
});

function snapOrganization(string $code, string $name): Organization
{
    return Organization::query()->create(['organization_type_id' => test()->type->id, 'code' => $code, 'name_en' => $name, 'status' => 'active']);
}

function snapVersion(string $name, HierarchyVersionStatus $status, string $from, array $pairs): HierarchyVersion
{
    $version = HierarchyVersion::query()->create(['version_name' => $name, 'status' => $status, 'effective_from' => $from, 'approval_date' => now()->subDay()]);
    foreach ($pairs as [$parent, $child]) {
        OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id, 'relationship_type' => 'reports_to']);
    }

    return $version;
}

test('a version replaced by a new one keeps its structure and counts, whatever happens to the institutions later', function (): void {
    $old = snapVersion('Old', HierarchyVersionStatus::Published, '2026-01-01', [[$this->parent, $this->child]]);
    $new = snapVersion('New', HierarchyVersionStatus::Draft, '2026-06-01', [[$this->parent, $this->child]]);

    app(PublishHierarchyVersionAction::class)->execute($new, $this->admin);
    $this->child->update(['name_en' => 'Renamed', 'code' => 'C-2']);
    $this->child->delete();

    $old->refresh();
    $snapshot = $old->archive_snapshot;

    expect($old->status)->toBe(HierarchyVersionStatus::Archived)
        ->and($snapshot['summary'])->toMatchArray(['organizations' => 2, 'relationships' => 1, 'roots' => 1, 'max_depth' => 1])
        ->and($snapshot['tree'][0]['code'])->toBe('P-1')
        ->and($snapshot['tree'][0]['children'][0]['name_en'])->toBe('Child')
        ->and($snapshot['tree'][0]['children'][0]['code'])->toBe('C-1')
        ->and($snapshot['by_type'][0]['count'])->toBe(2)
        ->and($new->fresh()->archive_snapshot)->toBeNull();
});

test('archiving a draft records it, and a snapshot is never rewritten', function (): void {
    $draft = snapVersion('Draft', HierarchyVersionStatus::Draft, '2026-01-01', [[$this->parent, $this->child]]);

    app(ArchiveHierarchyVersionAction::class)->execute($draft, $this->admin);
    $capturedAt = $draft->fresh()->archive_snapshot['captured_at'];
    $this->child->update(['name_en' => 'Renamed']);
    app(HierarchyVersionSnapshotService::class)->capture($draft->fresh());

    expect($draft->fresh()->archive_snapshot['captured_at'])->toBe($capturedAt)
        ->and($draft->fresh()->archive_snapshot['tree'][0]['children'][0]['name_en'])->toBe('Child');
});

test('an archived version still shows its archived institutions in the tree and the relationship table', function (): void {
    $version = snapVersion('Old', HierarchyVersionStatus::Archived, '2026-01-01', [[$this->parent, $this->child]]);
    $this->parent->delete();
    $this->child->delete();

    expect(app(HierarchyTreeService::class)->buildFullTree($version, $this->admin))->toHaveCount(1);

    $this->actingAs($this->admin)->get(route('hierarchy-versions.show', $version))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tree', 1)
            ->where('edges.0.child_organization.code', 'C-1'));
});

test('the command records versions archived earlier, and a scoped reader is not shown the whole structure', function (): void {
    $version = snapVersion('Old', HierarchyVersionStatus::Archived, '2026-01-01', [[$this->parent, $this->child]]);

    $this->artisan('hierarchy:snapshot-archived')->assertSuccessful();
    expect($version->fresh()->archive_snapshot['summary']['relationships'])->toBe(1);

    $this->actingAs($this->admin)->get(route('hierarchy-versions.show', $version))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('snapshot.summary.organizations', 2));

    $scoped = User::factory()->create();
    $scoped->assignRole('Scoped Viewer');
    $this->actingAs($scoped)->get(route('hierarchy-versions.show', $version))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('snapshot', null));
});
