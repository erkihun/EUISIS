<?php

declare(strict_types=1);

use App\Actions\Organizations\GetOrganizationFullTreeAction;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationNameHistory;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['organizations.view', 'organizations.manage'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->givePermissionTo(['organizations.view', 'organizations.manage']);
    Role::findOrCreate('Viewer', 'web')->givePermissionTo(['organizations.view']);
});

function makeOrgType(): OrganizationType
{
    return OrganizationType::query()->create(['code' => 'dept', 'name_en' => 'Department']);
}

function makeOrg(OrganizationType $type, string $code = 'ORG-1'): Organization
{
    return Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => $code,
        'name_en' => 'Test Organization',
        'status' => OrganizationStatus::Active,
    ]);
}

function managerUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function viewerUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('Viewer');

    return $user;
}

// ── Index ──────────────────────────────────────────────────────────────────

test('guests are redirected from the organizations index', function (): void {
    $this->get(route('organizations.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can view the organizations index', function (): void {
    $this->actingAs(viewerUser())
        ->get(route('organizations.index'))
        ->assertOk();
});

// ── Create page ────────────────────────────────────────────────────────────

test('users without organizations.manage cannot access the create page', function (): void {
    $this->actingAs(viewerUser())
        ->get(route('organizations.create'))
        ->assertForbidden();
});

test('users with organizations.manage can access the create page', function (): void {
    $this->actingAs(managerUser())
        ->get(route('organizations.create'))
        ->assertOk();
});

// ── Store ──────────────────────────────────────────────────────────────────

test('users without organizations.manage cannot create an organization', function (): void {
    $type = makeOrgType();

    $this->actingAs(viewerUser())
        ->post(route('organizations.store'), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-NEW',
            'name_en' => 'New Org',
            'status' => 'active',
        ])
        ->assertForbidden();
});

test('users with organizations.manage can create an organization', function (): void {
    $type = makeOrgType();

    $this->actingAs(managerUser())
        ->post(route('organizations.store'), [
            'organization_type_id' => $type->id,
            'code' => 'ORG-NEW',
            'name_en' => 'New Org',
            'status' => 'active',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('organizations', ['code' => 'ORG-NEW']);
});

// ── Edit page ──────────────────────────────────────────────────────────────

test('users without organizations.manage cannot access the edit page', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(viewerUser())
        ->get(route('organizations.edit', $org))
        ->assertForbidden();
});

test('users with organizations.manage can access the edit page', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(managerUser())
        ->get(route('organizations.edit', $org))
        ->assertOk();
});

// ── Update ─────────────────────────────────────────────────────────────────

test('users without organizations.manage cannot update an organization', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(viewerUser())
        ->patch(route('organizations.update', $org), [
            'organization_type_id' => $org->organization_type_id,
            'code' => $org->code,
            'name_en' => 'Changed',
            'status' => 'active',
        ])
        ->assertForbidden();
});

test('users with organizations.manage can update an organization', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(managerUser())
        ->patch(route('organizations.update', $org), [
            'organization_type_id' => $org->organization_type_id,
            'code' => $org->code,
            'name_en' => 'Updated Name',
            'status' => 'active',
        ])
        ->assertRedirect(route('organizations.show', $org));

    expect($org->fresh()->name_en)->toBe('Updated Name');
});

test('updating an organization English name creates name history', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(managerUser())
        ->patch(route('organizations.update', $org), [
            'organization_type_id' => $org->organization_type_id,
            'code' => $org->code,
            'name_en' => 'Updated English Name',
            'name_am' => $org->name_am,
            'status' => 'active',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ])
        ->assertRedirect(route('organizations.show', $org));

    $history = OrganizationNameHistory::query()
        ->where('organization_id', $org->id)
        ->where('name_en', 'Updated English Name')
        ->firstOrFail();

    expect($history->effective_from?->toDateString())->toBe('2026-01-01')
        ->and($history->effective_to)->toBeNull();
});

test('updating an organization Amharic name creates name history', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(managerUser())
        ->patch(route('organizations.update', $org), [
            'organization_type_id' => $org->organization_type_id,
            'code' => $org->code,
            'name_en' => $org->name_en,
            'name_am' => 'Updated Amharic Name',
            'status' => 'active',
            'effective_from' => '2026-02-01',
            'effective_to' => null,
        ])
        ->assertRedirect(route('organizations.show', $org));

    $history = OrganizationNameHistory::query()
        ->where('organization_id', $org->id)
        ->where('name_am', 'Updated Amharic Name')
        ->firstOrFail();

    expect($history->name_en)->toBe($org->name_en)
        ->and($history->effective_from?->toDateString())->toBe('2026-02-01')
        ->and($history->effective_to)->toBeNull();
});

test('updating organization dates syncs current name history dates', function (): void {
    $org = makeOrg(makeOrgType());
    $history = OrganizationNameHistory::query()->create([
        'organization_id' => $org->id,
        'name_en' => $org->name_en,
        'name_am' => $org->name_am,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    $this->actingAs(managerUser())
        ->patch(route('organizations.update', $org), [
            'organization_type_id' => $org->organization_type_id,
            'code' => $org->code,
            'name_en' => $org->name_en,
            'name_am' => $org->name_am,
            'status' => 'active',
            'effective_from' => '2026-03-01',
            'effective_to' => '2026-12-31',
        ])
        ->assertRedirect(route('organizations.show', $org));

    $history->refresh();

    expect($history->effective_from?->toDateString())->toBe('2026-03-01')
        ->and($history->effective_to?->toDateString())->toBe('2026-12-31');
});

test('organization detail payload sends date fields as plain date strings', function (): void {
    $org = makeOrg(makeOrgType());
    $org->update([
        'effective_from' => '2026-06-08',
        'effective_to' => '2026-12-31',
    ]);
    OrganizationNameHistory::query()->create([
        'organization_id' => $org->id,
        'name_en' => $org->name_en,
        'name_am' => $org->name_am,
        'effective_from' => '2026-06-08',
        'effective_to' => '2026-12-31',
    ]);

    $this->actingAs(managerUser())
        ->get(route('organizations.show', $org))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Organizations/Show')
            ->where('organization.effective_from', '2026-06-08')
            ->where('organization.effective_to', '2026-12-31')
            ->where('organization.name_histories.0.effective_from', '2026-06-08')
            ->where('organization.name_histories.0.effective_to', '2026-12-31')
        );
});

test('organization detail exposes a minimal full structure tree', function (): void {
    $org = makeOrg(makeOrgType());
    $unit = OrganizationUnit::query()->create([
        'organization_id' => $org->id,
        'unit_type' => 'department',
        'code' => 'UNIT-TREE',
        'name_en' => 'People Directorate',
        'status' => 'active',
    ]);
    $position = Position::query()->create([
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'POS-TREE',
        'title_en' => 'HR Officer',
        'is_active' => true,
    ]);
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-TREE',
        'first_name' => 'Sara',
        'last_name' => 'Tesfaye',
        'full_name' => 'Sara Tesfaye',
        'status' => 'active',
    ]);
    EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'assignment_status' => 'active',
        'effective_from' => '2026-07-01',
        'is_current' => true,
    ]);

    $tree = app(GetOrganizationFullTreeAction::class)->execute($org->load('type'));

    expect($tree['units'][0]['code'])->toBe('UNIT-TREE')
        ->and($tree['units'][0]['positions'][0]['code'])->toBe('POS-TREE')
        ->and($tree['units'][0]['positions'][0]['occupancy'])->toBe('occupied')
        ->and($tree['units'][0]['positions'][0]['assignment']['employee'])
        ->toMatchArray(['employee_number' => 'EMP-TREE', 'full_name' => 'Sara Tesfaye'])
        ->not->toHaveKeys(['national_id', 'phone', 'email', 'date_of_birth'])
        ->and($tree['units'][0]['positions'][0]['assignment']['effective_from'])->toBe('2026-07-01');
});

test('organization show includes the full tree component contract', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Organizations/Show.tsx'));
    $component = file_get_contents(resource_path('js/Components/organizations/OrganizationStructureTree.tsx'));

    expect($source)->toContain('OrganizationStructureTree', 'structureTree')
        ->and($component)->toContain('LocalizedDateDisplay', 'fullOrganizationStructure', 'searchStructure', 'directOrganizationPositions');
});

// ── Archive ────────────────────────────────────────────────────────────────

test('users without organizations.manage cannot archive an organization', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(viewerUser())
        ->delete(route('organizations.archive', $org))
        ->assertForbidden();
});

test('archiving sets status to archived and redirects to index', function (): void {
    $org = makeOrg(makeOrgType());

    $this->actingAs(managerUser())
        ->delete(route('organizations.archive', $org))
        ->assertRedirect(route('organizations.index'));

    expect($org->fresh()->status)->toBe(OrganizationStatus::Archived);
});
