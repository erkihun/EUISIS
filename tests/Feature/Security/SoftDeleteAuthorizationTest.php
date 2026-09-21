<?php

declare(strict_types=1);

use App\Models\ExternalApplication;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase-2: soft-delete and recycle-bin authorization.
 *
 * SEC2-004 — permanent destruction used to be gated on
 * `recycle-bin.restore`, so a role meant only to RECOVER records could
 * irreversibly destroy them. `recycle-bin.forceDelete` existed in the
 * permission catalog the whole time and was never checked.
 */
beforeEach(function (): void {
    foreach ([
        'recycle-bin.view', 'recycle-bin.viewDetails',
        'recycle-bin.restore', 'recycle-bin.forceDelete',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    // A role that may recover records but must never destroy them.
    Role::findOrCreate('Records Recovery', 'web')->syncPermissions([
        'recycle-bin.view', 'recycle-bin.viewDetails', 'recycle-bin.restore',
    ]);

    Role::findOrCreate('Records Custodian', 'web')->syncPermissions([
        'recycle-bin.view', 'recycle-bin.viewDetails',
        'recycle-bin.restore', 'recycle-bin.forceDelete',
    ]);
});

function recycleUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

test('SEC2-004: restore permission alone cannot permanently destroy a record', function (): void {
    $user = recycleUser('Records Recovery');

    // Any type/id is fine — authorization is checked before the record is touched.
    $this->actingAs($user)
        ->delete(route('recycle-bin.force-delete', ['type' => 'employees', 'id' => (string) Str::uuid()]))
        ->assertForbidden();
});

test('the dedicated forceDelete permission is what grants destruction', function (): void {
    $user = recycleUser('Records Custodian');

    // Not 403: authorization passes, so the request reaches the service.
    $response = $this->actingAs($user)
        ->delete(route('recycle-bin.force-delete', ['type' => 'employees', 'id' => (string) Str::uuid()]));

    expect($response->status())->not->toBe(403);
});

test('a user with neither permission cannot reach the recycle bin at all', function (): void {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)->get(route('recycle-bin.index'))->assertForbidden();
});

/*
 * A soft-deleted integration must stop authenticating immediately. Sanctum
 * resolves the token's owner through the model, and SoftDeletes excludes a
 * trashed row, so a deleted application's live token must be rejected.
 */
test('a soft-deleted external application can no longer use its token', function (): void {
    $application = ExternalApplication::query()->create([
        'name' => 'Doomed Integration',
        'code' => 'DOOM-'.Str::random(6),
        'status' => 'active',
        'allowed_scopes' => ['employees.basic_read'],
        'rate_limit_per_minute' => 60,
    ]);

    $token = $application->createToken('phase2', $application->grantableScopes())->plainTextToken;

    $application->delete();

    // `fresh()` bypasses global scopes, so query explicitly instead.
    expect(ExternalApplication::query()->whereKey($application->getKey())->exists())->toBeFalse()
        ->and(ExternalApplication::withTrashed()->whereKey($application->getKey())->exists())->toBeTrue();

    $this->withToken($token)->getJson('/api/v1/employees')->assertUnauthorized();
});
