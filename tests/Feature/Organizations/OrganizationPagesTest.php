<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Presentation-layer coverage for the redesigned organization pages: KPI
 * summary props, page rendering, and permission-aware action flags. Business
 * logic is covered elsewhere (OrganizationCrudTest, OrganizationDeletionGuardTest).
 */
beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('OP Manager', 'web')->givePermissionTo(['organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete']);
    Role::findOrCreate('OP Viewer', 'web')->givePermissionTo(['organizations.view']);

    $this->opType = OrganizationType::query()->create(['code' => 'OP-TYPE', 'name_en' => 'Pages Test Type']);
});

function opOrg(string $code, string $status = 'active'): Organization
{
    return Organization::query()->create([
        'organization_type_id' => test()->opType->id,
        'code' => $code,
        'name_en' => 'Org '.$code,
        'status' => $status,
        'effective_from' => now()->toDateString(),
    ]);
}

function opManager(): User
{
    return opCitywide(tap(User::factory()->create())->assignRole('OP Manager'));
}

function opViewer(): User
{
    return opCitywide(tap(User::factory()->create())->assignRole('OP Viewer'));
}

// Organization-level roles need an explicit scope (ADR 0003).
function opCitywide(User $user): User
{
    UserOrganizationScope::query()->create([
        'user_id' => $user->id,
        'scope_type' => OrganizationScopeType::Citywide,
        'organization_id' => null,
        'is_active' => true,
    ]);

    return $user;
}

it('renders the index with KPI summary counts', function (): void {
    opOrg('OP-1', OrganizationStatus::Active->value);
    opOrg('OP-2', OrganizationStatus::Active->value);
    opOrg('OP-3', OrganizationStatus::Inactive->value);

    $this->actingAs(opManager())
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Index')
            ->where('stats.total', 3)
            ->where('stats.active', 2)
            ->where('stats.inactive', 1)
            ->where('stats.types', 1)
            ->has('organizations.data.0', fn (Assert $row) => $row
                ->has('created_at')
                ->has('can')
                ->has('deletion_blockers')
                ->etc()
            )
        );
});

it('does not display archived organizations on the index', function (): void {
    opOrg('OP-ACTIVE', OrganizationStatus::Active->value);
    opOrg('OP-ARCHIVED', OrganizationStatus::Archived->value);

    $this->actingAs(opManager())
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Index')
            ->where('stats.total', 1)
            ->where('stats.active', 1)
            ->where('stats.inactive', 0)
            ->has('organizations.data', 1)
            ->where('organizations.data.0.code', 'OP-ACTIVE')
        );
});

it('renders the create page with organization types', function (): void {
    $this->actingAs(opManager())
        ->get(route('organizations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Create')
            ->has('organizationTypes')
        );
});

it('renders the edit page for an organization', function (): void {
    $org = opOrg('OP-EDIT');

    $this->actingAs(opManager())
        ->get(route('organizations.edit', $org))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Edit')
            ->where('organization.id', $org->id)
            ->has('organizationTypes')
        );
});

it('renders the show page with structure summary and formatted date props', function (): void {
    $org = opOrg('OP-SHOW');

    $this->actingAs(opManager())
        ->get(route('organizations.show', $org))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Show')
            ->has('structureSummary', fn (Assert $s) => $s
                ->where('units', 0)
                ->where('positions', 0)
                ->where('descendants', 0)
            )
            ->has('parentOrganization')
            // Effective dates are sent as plain Y-m-d strings, never raw ISO datetimes.
            ->where('organization.effective_from', now()->toDateString())
        );
});

it('exposes permission-aware action flags per row', function (): void {
    opOrg('OP-PERM');

    // Manager: can manage → delete flag true
    $this->actingAs(opManager())
        ->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('organizations.data.0.can.delete', true));

    // Viewer: no manage permission → every action false
    $this->actingAs(opViewer())
        ->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizations.data.0.can.delete', false)
            ->where('organizations.data.0.can.update', false)
            ->where('organizations.data.0.can.archive', false)
        );
});

function opCityAdmin(): User
{
    Role::findOrCreate('City Admin', 'web')->givePermissionTo(['organizations.view', 'organizations.create', 'organizations.update', 'organizations.delete']);

    return tap(User::factory()->create())->assignRole('City Admin');
}

function opSortedCodes(array $query): array
{
    $codes = [];
    test()->actingAs(opManager())
        ->get(route('organizations.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$codes): void {
            $props = $page->toArray()['props'];
            $codes = array_column($props['organizations']['data'], 'code');
            test()->sortProps = $props['sort'];
        });

    return $codes;
}

test('the list sorts by a chosen column in either direction', function (): void {
    opOrg('B-2');
    opOrg('A-1');
    opOrg('C-3');

    expect(opSortedCodes(['sort' => 'code', 'direction' => 'asc']))->toBe(['A-1', 'B-2', 'C-3'])
        ->and(opSortedCodes(['sort' => 'code', 'direction' => 'desc']))->toBe(['C-3', 'B-2', 'A-1'])
        ->and(opSortedCodes(['sort' => 'name', 'direction' => 'desc']))->toBe(['C-3', 'B-2', 'A-1'])
        ->and($this->sortProps)->toBe(['column' => 'name', 'direction' => 'desc']);
});

test('the list sorts by the name of the type, the category and the parent', function (): void {
    $zebra = OrganizationType::query()->create(['code' => 'ZEBRA', 'name_en' => 'Zebra Type', 'category' => 'root']);
    $alpha = OrganizationType::query()->create(['code' => 'ALPHA', 'name_en' => 'Alpha Type', 'category' => 'geographic']);
    $first = Organization::query()->create(['organization_type_id' => $zebra->id, 'code' => 'Z-1', 'name_en' => 'Parent Z', 'status' => 'active']);
    $second = Organization::query()->create(['organization_type_id' => $alpha->id, 'code' => 'A-1', 'name_en' => 'Parent A', 'status' => 'active']);

    expect(opSortedCodes(['sort' => 'type', 'direction' => 'asc']))->toBe(['A-1', 'Z-1'])
        ->and(opSortedCodes(['sort' => 'type', 'direction' => 'desc']))->toBe(['Z-1', 'A-1'])
        ->and(opSortedCodes(['sort' => 'category', 'direction' => 'desc']))->toBe(['Z-1', 'A-1']);

    $version = HierarchyVersion::query()->create(['version_name' => 'Sort test', 'status' => 'published', 'effective_from' => now()->toDateString(), 'approval_date' => now()]);
    $childOfZ = opOrg('CHILD-Z');
    $childOfA = opOrg('CHILD-A');
    OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $first->id, 'child_organization_id' => $childOfZ->id, 'relationship_type' => 'reports_to']);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $second->id, 'child_organization_id' => $childOfA->id, 'relationship_type' => 'reports_to']);

    expect(array_slice(opSortedCodes(['sort' => 'parent', 'direction' => 'asc']), 0, 2))->toBe(['CHILD-A', 'CHILD-Z']);
});

test('a column outside the list falls back to the name instead of reaching the query', function (): void {
    opOrg('B-2');
    opOrg('A-1');

    expect(opSortedCodes(['sort' => 'id;drop table organizations', 'direction' => 'sideways']))->toBe(['A-1', 'B-2'])
        ->and($this->sortProps)->toBe(['column' => 'name', 'direction' => 'asc']);
});

test('an institution page shows its place in every hierarchy version, archived parents included', function (): void {
    $parent = opOrg('HIST-P');
    $child = opOrg('HIST-C');
    $old = HierarchyVersion::query()->create(['version_name' => 'Older', 'status' => 'archived', 'effective_from' => '2026-01-01']);
    $current = HierarchyVersion::query()->create(['version_name' => 'Current', 'status' => 'published', 'effective_from' => '2026-06-01', 'approval_date' => now()]);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $old->id, 'parent_organization_id' => $parent->id, 'child_organization_id' => $child->id, 'relationship_type' => 'reports_to']);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $current->id, 'parent_organization_id' => $child->id, 'child_organization_id' => opOrg('HIST-D')->id, 'relationship_type' => 'reports_to']);
    $parent->delete();

    $this->actingAs(opManager())->get(route('organizations.show', $child))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('versionHistory.0.version_name', 'Current')
            ->where('versionHistory.0.parent', null)
            ->where('versionHistory.0.children_count', 1)
            ->where('versionHistory.1.version_name', 'Older')
            ->where('versionHistory.1.status', 'archived')
            ->where('versionHistory.1.parent.code', 'HIST-P'));
});

test('an archived institution opens read-only and offers no action', function (): void {
    $archived = opOrg('ARCH-1', 'archived');
    $archived->delete();
    $manager = opCityAdmin();

    $this->actingAs($manager)->get(route('organizations.show', $archived->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('isArchived', true)
            ->where('organization.code', 'ARCH-1')
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('can.archive', false)
            ->where('can.deactivate', false)
            ->where('can.createChild', false));

    $this->actingAs($manager)->get(route('organizations.edit', $archived->id))->assertNotFound();
    $this->actingAs($manager)->patch(route('organizations.update', $archived->id), ['name_en' => 'Changed'])->assertNotFound();
    $this->actingAs($manager)->delete(route('organizations.destroy', $archived->id))->assertNotFound();

    expect($archived->fresh()->name_en)->toBe('Org ARCH-1');
});

test('the working list hides archived institutions and the archived filter shows them without actions', function (): void {
    opOrg('LIVE-1');
    $archived = opOrg('ARCH-2', 'archived');
    $archived->delete();
    $manager = opCityAdmin();

    $this->actingAs($manager)->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page->has('organizations.data', 1)->where('organizations.data.0.code', 'LIVE-1'));

    $this->actingAs($manager)->get(route('organizations.index', ['status' => 'archived']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations.data', 1)
            ->where('organizations.data.0.code', 'ARCH-2')
            ->where('organizations.data.0.can.update', false)
            ->where('organizations.data.0.can.delete', false));
});

test('only city-wide administrators open archived institutions; a scoped reader is refused', function (): void {
    $archived = opOrg('ARCH-3', 'archived');
    $archived->delete();
    $mine = opOrg('MINE-1');
    $scoped = User::factory()->create();
    $scoped->assignRole('OP Viewer');
    UserOrganizationScope::query()->create([
        'user_id' => $scoped->id, 'organization_id' => $mine->id, 'scope_type' => OrganizationScopeType::Self,
        'is_active' => true, 'effective_from' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($scoped)->get(route('organizations.show', $archived->id))->assertForbidden();
});
