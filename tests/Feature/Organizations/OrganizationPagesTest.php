<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Presentation-layer coverage for the redesigned organization pages: KPI
 * summary props, page rendering, and permission-aware action flags. Business
 * logic is covered elsewhere (OrganizationCrudTest, OrganizationDeletionGuardTest).
 */
beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.manage'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('OP Manager', 'web')->givePermissionTo(['organizations.view', 'organizations.manage']);
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
    return tap(User::factory()->create())->assignRole('OP Manager');
}

function opViewer(): User
{
    return tap(User::factory()->create())->assignRole('OP Viewer');
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
            ->has('unassigned.0', fn (Assert $row) => $row
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
            ->has('unassigned', 1)
            ->where('unassigned.0.code', 'OP-ACTIVE')
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
        ->assertInertia(fn (Assert $page) => $page->where('unassigned.0.can.delete', true));

    // Viewer: no manage permission → every action false
    $this->actingAs(opViewer())
        ->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unassigned.0.can.delete', false)
            ->where('unassigned.0.can.update', false)
            ->where('unassigned.0.can.archive', false)
        );
});
