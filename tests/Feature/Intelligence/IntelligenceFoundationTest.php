<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use App\Support\Intelligence\IntelligenceRoles;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Addis One Intelligence foundations (docs/intelligence-architecture.md):
 * the launcher opens on intelligence.view, every area needs its own grant,
 * and a technical grant never opens a business area or the reverse.
 */

beforeEach(function (): void {
    foreach ([...IntelligencePermissions::all(), IntelligencePermissions::SETTINGS] as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

function intelligenceUser(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('intel-'.md5(implode(',', $permissions)), 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

test('the launcher needs intelligence.view and lists only the permitted areas', function (): void {
    $this->actingAs(intelligenceUser([]))->get(route('intelligence.index'))->assertForbidden();

    $executive = intelligenceUser([IntelligencePermissions::VIEW, IntelligencePermissions::EXECUTIVE]);

    $this->actingAs($executive)
        ->get(route('intelligence.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Intelligence/Index')
            ->has('areas', 2)
            ->where('areas.0.key', 'overview')
            ->where('areas.1.key', 'executive')
            ->where('areas.1.domain', 'business')
            ->where('ai.enabled', false));
});

test('a technical grant opens technical areas only, and a business grant business areas only', function (): void {
    $technician = intelligenceUser([IntelligencePermissions::VIEW, IntelligencePermissions::SYSTEM_HEALTH]);
    $executive = intelligenceUser([IntelligencePermissions::VIEW, IntelligencePermissions::EXECUTIVE]);

    $this->actingAs($technician)->get(route('intelligence.area', 'system_health'))->assertOk();
    $this->actingAs($technician)->get(route('intelligence.area', 'executive'))->assertForbidden();
    $this->actingAs($technician)->get(route('intelligence.area', 'workforce'))->assertForbidden();

    $this->actingAs($executive)->get(route('intelligence.area', 'executive'))->assertOk();
    $this->actingAs($executive)->get(route('intelligence.area', 'system_health'))->assertForbidden();
    $this->actingAs($executive)->get(route('intelligence.area', 'diagnostics'))->assertForbidden();
});

test('an unknown area is not found and the overview needs no extra grant', function (): void {
    $viewer = intelligenceUser([IntelligencePermissions::VIEW]);

    $this->actingAs($viewer)->get('/intelligence/not-an-area')->assertNotFound();
    $this->actingAs($viewer)->get(route('intelligence.area', 'overview'))->assertOk();
});

test('every area has a grant in the catalog and an icon that exists', function (): void {
    $icons = file_get_contents(base_path('resources/js/Components/NavIcons.tsx'));
    $catalog = PermissionCatalog::names();

    foreach (IntelligenceArea::cases() as $area) {
        expect($catalog)->toContain($area->permission())
            ->and($icons)->toContain("export const {$area->icon()} ");
    }

    expect(PermissionCatalog::problems())->toBe([]);
});

test('the city-wide business roles are withheld the technical grants', function (): void {
    $cityAdmin = DefaultRoleMatrix::permissionsFor('City Admin');
    $systemAdmin = DefaultRoleMatrix::permissionsFor('System Admin');

    expect($cityAdmin)->toContain(IntelligencePermissions::EXECUTIVE)
        ->toContain(IntelligencePermissions::GOVERNANCE)
        ->not->toContain(IntelligencePermissions::DIAGNOSTICS)
        ->not->toContain(IntelligencePermissions::SYSTEM_HEALTH)
        ->and($systemAdmin)->toContain(IntelligencePermissions::DIAGNOSTICS);

    foreach (IntelligenceRoles::CITY_ADMIN_WITHHELD as $withheld) {
        expect($cityAdmin)->not->toContain($withheld);
    }
    expect(DefaultRoleMatrix::problems())->toBe([]);
});
