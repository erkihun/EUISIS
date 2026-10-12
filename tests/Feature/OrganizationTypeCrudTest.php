<?php

declare(strict_types=1);

use App\Models\OrganizationType;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach ([
        'organization-types.viewAny',
        'organization-types.view',
        'organization-types.create',
        'organization-types.update',
        'organization-types.delete',
        'organization-types.restore',
        'organization-types.viewDeleted',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('OrgType Admin', 'web')->syncPermissions([
        'organization-types.viewAny',
        'organization-types.view',
        'organization-types.create',
        'organization-types.update',
        'organization-types.delete',
        'organization-types.restore',
        'organization-types.viewDeleted',
    ]);

    Role::findOrCreate('OrgType Viewer', 'web')->syncPermissions([
        'organization-types.viewAny',
    ]);
});

function orgTypeAdminUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('OrgType Admin');

    return $user;
}

function orgTypeViewerUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('OrgType Viewer');

    return $user;
}

function makeTestOrgType(string $code = 'TEST'): OrganizationType
{
    return OrganizationType::query()->create(['code' => $code, 'name_en' => 'Test Type']);
}

// ── Index ──────────────────────────────────────────────────────────────────

test('guests are redirected from the organization types index', function (): void {
    $this->get(route('organization-types.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users with viewAny can view the org types index', function (): void {
    $this->actingAs(orgTypeViewerUser())
        ->get(route('organization-types.index'))
        ->assertOk();
});

test('the org types index filters by search and status and paginates like every other list', function (): void {
    OrganizationType::query()->create(['code' => 'MIN', 'name_en' => 'Ministry', 'is_active' => true]);
    OrganizationType::query()->create(['code' => 'AGY', 'name_en' => 'Agency', 'is_active' => true]);
    OrganizationType::query()->create(['code' => 'OLD', 'name_en' => 'Retired Bureau', 'is_active' => false]);

    $this->actingAs(orgTypeViewerUser())
        ->get(route('organization-types.index', ['search' => 'agen']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationTypes/Index')
            ->has('types.data', 1)
            ->where('types.data.0.code', 'AGY')
            ->where('filters.search', 'agen')
        );

    $this->actingAs(orgTypeViewerUser())
        ->get(route('organization-types.index', ['status' => 'inactive']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('types.data', 1)
            ->where('types.data.0.code', 'OLD')
        );

    $this->actingAs(orgTypeViewerUser())
        ->get(route('organization-types.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('types.data', 3)
            ->where('types.total', 3)
            ->where('filters.search', '')
        );
});

// ── Create page ────────────────────────────────────────────────────────────

test('users without create permission cannot access the create page', function (): void {
    $this->actingAs(orgTypeViewerUser())
        ->get(route('organization-types.create'))
        ->assertForbidden();
});

test('users with create permission can access the create page', function (): void {
    $this->actingAs(orgTypeAdminUser())
        ->get(route('organization-types.create'))
        ->assertOk();
});

// ── Store ──────────────────────────────────────────────────────────────────

test('users without create permission cannot store an org type', function (): void {
    $this->actingAs(orgTypeViewerUser())
        ->post(route('organization-types.store'), [
            'code' => 'NEW',
            'name_en' => 'New Type',
        ])
        ->assertForbidden();
});

test('users with create permission can store an org type', function (): void {
    $this->actingAs(orgTypeAdminUser())
        ->post(route('organization-types.store'), [
            'code' => 'NEW',
            'prefix' => 'bur',
            'name_en' => 'New Type',
        ])
        ->assertRedirect(route('organization-types.index'));

    $this->assertDatabaseHas('organization_types', ['code' => 'NEW', 'prefix' => 'BUR', 'name_en' => 'New Type']);
});

test('organization type prefix allows safe characters and rejects invalid values', function (): void {
    $this->actingAs(orgTypeAdminUser())
        ->post(route('organization-types.store'), [
            'code' => 'SAFE',
            'prefix' => 'B-01_A',
            'name_en' => 'Safe Prefix',
        ])
        ->assertRedirect(route('organization-types.index'));

    $this->assertDatabaseHas('organization_types', ['code' => 'SAFE', 'prefix' => 'B-01_A']);

    $this->actingAs(orgTypeAdminUser())
        ->post(route('organization-types.store'), [
            'code' => 'BAD',
            'prefix' => 'BAD PREFIX!',
            'name_en' => 'Bad Prefix',
        ])
        ->assertSessionHasErrors('prefix');
});

// ── Edit page ──────────────────────────────────────────────────────────────

test('users without update permission cannot access the edit page', function (): void {
    $type = makeTestOrgType();

    $this->actingAs(orgTypeViewerUser())
        ->get(route('organization-types.edit', $type))
        ->assertForbidden();
});

test('users with update permission can access the edit page', function (): void {
    $type = makeTestOrgType();

    $this->actingAs(orgTypeAdminUser())
        ->get(route('organization-types.edit', $type))
        ->assertOk();
});

// ── Update ─────────────────────────────────────────────────────────────────

test('users without update permission cannot update an org type', function (): void {
    $type = makeTestOrgType();

    $this->actingAs(orgTypeViewerUser())
        ->patch(route('organization-types.update', $type), [
            'code' => $type->code,
            'name_en' => 'Changed',
        ])
        ->assertForbidden();
});

test('users with update permission can update an org type', function (): void {
    $type = makeTestOrgType();

    $this->actingAs(orgTypeAdminUser())
        ->patch(route('organization-types.update', $type), [
            'code' => $type->code,
            'prefix' => 'sub',
            'name_en' => 'Updated Name',
        ])
        ->assertRedirect(route('organization-types.index'));

    expect($type->fresh()->name_en)->toBe('Updated Name')
        ->and($type->fresh()->prefix)->toBe('SUB');
});

test('organization type index and edit pages include prefix props', function (): void {
    $type = OrganizationType::query()->create([
        'code' => 'WITH-PREFIX',
        'prefix' => 'PFX',
        'name_en' => 'With Prefix',
    ]);

    $this->actingAs(orgTypeAdminUser())
        ->get(route('organization-types.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationTypes/Index')
            ->where('types.data.0.prefix', 'PFX')
        );

    $this->actingAs(orgTypeAdminUser())
        ->get(route('organization-types.edit', $type))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationTypes/Edit')
            ->where('type.prefix', 'PFX')
        );
});

test('organization type show page displays prefix prop', function (): void {
    $type = OrganizationType::query()->create([
        'code' => 'DETAIL',
        'prefix' => 'DET',
        'name_en' => 'Detail Type',
    ]);

    $this->actingAs(orgTypeAdminUser())
        ->get(route('organization-types.show', $type))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('OrganizationTypes/Show')
            ->where('type.prefix', 'DET')
        );
});

test('users without view permission cannot access organization type details', function (): void {
    $type = makeTestOrgType();

    $this->actingAs(orgTypeViewerUser())
        ->get(route('organization-types.show', $type))
        ->assertForbidden();
});

test('organization type prefix translation keys exist', function (): void {
    expect(trans('organization-types.prefix', [], 'en'))->toBe('Prefix')
        ->and(trans('organization-types.prefix_invalid', [], 'en'))->toContain('letters')
        ->and(trans('organization-types.prefix', [], 'am'))->toBe('ቅድመ ኮድ')
        ->and(file_get_contents(resource_path('js/i18n/en/organizationTypes.ts')))->toContain('prefixHelp')
        ->and(file_get_contents(resource_path('js/i18n/am/organizationTypes.ts')))->toContain('ቅድመ ኮድ');
});

// ── Delete (soft delete) ───────────────────────────────────────────────────

test('users without delete permission cannot delete an org type', function (): void {
    $type = makeTestOrgType();

    $this->actingAs(orgTypeViewerUser())
        ->delete(route('organization-types.destroy', $type))
        ->assertForbidden();
});

test('deleting soft-deletes the type and redirects to index', function (): void {
    $type = makeTestOrgType();

    $this->actingAs(orgTypeAdminUser())
        ->delete(route('organization-types.destroy', $type))
        ->assertRedirect(route('organization-types.index'));

    expect(OrganizationType::find($type->id))->toBeNull(); // excluded by default scope
    expect(OrganizationType::withTrashed()->find($type->id))->not->toBeNull();
    expect(OrganizationType::withTrashed()->find($type->id)->deleted_at)->not->toBeNull();
});

// ── Restore ────────────────────────────────────────────────────────────────

test('users without restore permission cannot restore an org type', function (): void {
    $type = makeTestOrgType();
    $type->delete();

    $this->actingAs(orgTypeViewerUser())
        ->post(route('organization-types.restore', $type->id))
        ->assertForbidden();
});

test('restoring clears deleted_at and redirects to index', function (): void {
    $type = makeTestOrgType();
    $type->delete();

    $this->actingAs(orgTypeAdminUser())
        ->post(route('organization-types.restore', $type->id))
        ->assertRedirect(route('organization-types.index'));

    expect(OrganizationType::find($type->id))->not->toBeNull();
    expect(OrganizationType::find($type->id)->deleted_at)->toBeNull();
});
