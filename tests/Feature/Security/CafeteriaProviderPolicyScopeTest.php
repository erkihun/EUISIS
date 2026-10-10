<?php

declare(strict_types=1);

use App\Models\CafeteriaProvider;
use App\Models\CafeteriaTransaction;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaProviderAccessService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/*
 * Production risk register SEC-7: changing, archiving or re-assigning a
 * cafeteria provider needs the same provider access as viewing it.
 */
beforeEach(function (): void {
    foreach (['update', 'assignInstitution', 'archive', 'restore', 'view', 'viewAll'] as $action) {
        Permission::findOrCreate("cafeteria_providers.{$action}", 'web');
    }
    $this->provider = CafeteriaProvider::query()->create([
        'code' => 'CP-'.Str::upper(Str::random(6)), 'name_en' => 'Somebody else\'s cafeteria', 'is_active' => true,
    ]);
});

it('refuses update, re-assignment and archive of a provider the user cannot access', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['cafeteria_providers.update', 'cafeteria_providers.assignInstitution', 'cafeteria_providers.archive', 'cafeteria_providers.restore']);

    foreach (['update', 'updateInstitution', 'archive', 'restore'] as $ability) {
        expect(Gate::forUser($user->fresh())->allows($ability, $this->provider))->toBeFalse("{$ability} allowed");
    }
});

it('still allows oversight users who may access every provider', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['cafeteria_providers.update', 'cafeteria_providers.viewAll']);

    expect(Gate::forUser($user->fresh())->allows('update', $this->provider))->toBeTrue();
});

/*
 * SEC-7 decision: `cafeteria_providers.viewAll` is city-wide oversight, so it
 * applies only to users whose organization scope is unrestricted. A scoped
 * holder falls back to the cafeterias assigned to them, like everyone else.
 */
it('limits a scoped viewAll holder to the cafeterias assigned to them', function (): void {
    $type = OrganizationType::query()->firstOrCreate(['code' => 'SEC7'], ['name_en' => 'SEC-7']);
    $org = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SEC7-ORG', 'name_en' => 'Scoped org', 'status' => 'active']);
    $user = User::factory()->create();
    $user->givePermissionTo(['cafeteria_providers.view', 'cafeteria_providers.viewAll', 'cafeteria_providers.update']);
    $user->organizationScopes()->create(['organization_id' => $org->id, 'scope_type' => 'self', 'is_active' => true]);
    $user = $user->fresh();
    $access = app(CafeteriaProviderAccessService::class);

    expect($access->canAccessAllProviders($user))->toBeFalse()
        ->and($access->canAccessProvider($user, $this->provider))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $this->provider))->toBeFalse()
        ->and($access->filterProviderScopedQuery($user, CafeteriaTransaction::query())->toRawSql())->toContain('where');
});
