<?php

declare(strict_types=1);

use App\Models\CafeteriaProvider;
use App\Models\User;
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
