<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Modules\Court\Domain\Models\CourtStaffMember;

/*
| ADR 0020: EUISIS manages who works in the court. A role made on the EUISIS
| Roles page with `court.*` permissions is all an account needs; the court has
| no Users, Roles, Permissions or System Settings screens of its own.
*/

function courtRoleAccount(array $permissions): User
{
    $role = Role::query()->create(['name' => 'Court clerk '.uniqid(), 'guard_name' => 'web', 'scope_type' => 'global']);
    $role->givePermissionTo($permissions);
    $account = User::factory()->create();
    $account->assignRole($role);

    return $account;
}

test('an EUISIS role with court permissions opens the court, and its court record is made on the way in', function (): void {
    $account = courtRoleAccount(['court.cases.view']);

    $this->actingAs($account)->get(route('court.cases.index'))->assertOk();

    $member = CourtStaffMember::query()->find($account->id);
    expect($member)->not->toBeNull()
        ->and($member->email)->toBe($account->email)
        ->and($member->hasPermission('cases.view'))->toBeTrue()
        ->and($member->hasPermission('cases.delete'))->toBeFalse();
});

test('a permission the EUISIS role lacks keeps its page closed', function (): void {
    $account = courtRoleAccount(['court.cases.view']);

    $this->actingAs($account)->get(route('court.decisions.index'))->assertRedirect(route('court.dashboard'));
});

test('an account without court permissions does not get in', function (): void {
    $this->actingAs(User::factory()->create())->get(route('court.cases.index'))->assertForbidden();
    expect(CourtStaffMember::query()->count())->toBe(0);
});

test('the court has no account, role, permission or system settings screens of its own', function (): void {
    foreach (['court.users.index', 'court.roles.index', 'court.permissions.index', 'court.settings.system.edit', 'court.admin.roles.switch'] as $name) {
        expect(\Illuminate\Support\Facades\Route::has($name))->toBeFalse($name);
    }
});

test('a staff page keeps the EUISIS menu: no court workspace', function (): void {
    $account = courtRoleAccount(['court.cases.view']);

    $this->actingAs($account)->get(route('court.cases.index'))
        ->assertInertia(fn ($page) => $page->where('workspace', null));
});
