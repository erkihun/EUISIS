<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * Accounts: what a staff member may change on their own record, who may hand
 * out the admin role or delete an admin, clearing roles, team hierarchy loops,
 * the permissions list page size and which roles may be deleted.
 */

function acctHardeningRole(string $name, array $permissions = []): Role
{
    $role = Role::query()->firstOrCreate(['name' => $name]);
    $role->permissions()->sync(collect($permissions)->map(
        fn (string $permission): int => Permission::query()->firstOrCreate(['name' => $permission], ['label' => Str::headline($permission)])->id,
    )->all());

    return $role;
}

function acctHardeningUser(Role ...$roles): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now(), 'password' => Hash::make('OldPassword123!')]);
    $user->roles()->sync(collect($roles)->pluck('id')->all());

    return $user;
}

function acctHardeningAdmin(): User
{
    return acctHardeningUser(acctHardeningRole('admin'));
}

/** The fields the account form sends, for $user. */
function acctHardeningForm(User $user, array $overrides = []): array
{
    [$first, $last] = array_pad(explode(' ', (string) $user->name, 2), 2, 'Person');

    return [
        'first_name' => $first, 'middle_name' => '', 'last_name' => $last ?: 'Person',
        'email' => $user->email, 'gender' => '', 'date_of_birth' => '', 'national_id' => '',
        'position' => '', 'phone' => '', 'address' => '', 'remove_avatar' => 0,
        ...$overrides,
    ];
}

// ---------------------------------------------------------------- self-edit

test('a staff member editing their own record cannot change roles, status, password, signature or stamp', function (): void {
    Storage::fake('court_public');
    Storage::disk('court_public')->put('signatures/mine.png', 'sig');
    Storage::disk('court_public')->put('stamps/mine.png', 'stamp');
    $admin = acctHardeningRole('admin');
    $clerk = acctHardeningRole('clerk', ['cases.view']);
    $user = acctHardeningUser($clerk);
    $user->forceFill(['signature_path' => 'signatures/mine.png', 'stamp_path' => 'stamps/mine.png'])->save();

    $this->actingAs($user)->get(route('court.users.edit', $user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Users/Edit')->where('canManage', false));

    $this->actingAs($user)->patch(route('court.users.update', $user).'?roles[]='.$admin->id, acctHardeningForm($user, [
        'position' => 'Senior Clerk',
        'status' => 'inactive',
        'roles' => [$admin->id],
        'password' => 'TakenOver123!',
        'password_confirmation' => 'TakenOver123!',
        'signature' => UploadedFile::fake()->image('forged.png'),
        'remove_stamp' => 1,
    ]))->assertSessionHasNoErrors()->assertRedirect(route('court.users.show', $user));

    $user->refresh();
    expect($user->position)->toBe('Senior Clerk')
        ->and($user->status)->toBe('active')
        ->and($user->roles()->pluck('court_roles.id')->all())->toBe([$clerk->id])
        ->and(Hash::check('OldPassword123!', $user->password))->toBeTrue()
        ->and($user->signature_path)->toBe('signatures/mine.png')
        ->and($user->stamp_path)->toBe('stamps/mine.png');
    Storage::disk('court_public')->assertExists('stamps/mine.png');
});

// ---------------------------------------------------------------- admin role

test('a users.manage holder cannot hand out the admin role; an administrator can', function (): void {
    $adminRole = acctHardeningRole('admin');
    $clerk = acctHardeningRole('clerk');
    $manager = acctHardeningUser(acctHardeningRole('user-manager', ['users.manage']));
    $target = acctHardeningUser($clerk);

    $this->actingAs($manager)->get(route('court.users.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roles', fn ($roles): bool => collect($roles)->firstWhere('id', $adminRole->id)['disabled'] === true
                && collect($roles)->firstWhere('id', $clerk->id)['disabled'] === false));

    $this->actingAs($manager)->from(route('court.users.edit', $target))
        ->patch(route('court.users.update', $target), acctHardeningForm($target, ['status' => 'active', 'roles' => [$clerk->id, $adminRole->id]]))
        ->assertSessionHasErrors(['roles' => __('court::users.admin_role_admin_only')]);
    expect($target->roles()->pluck('court_roles.id')->all())->toBe([$clerk->id]);

    $email = 'promoted-'.Str::lower(Str::random(6)).'@court.test';
    $this->actingAs($manager)->post(route('court.users.store'), [
        'first_name' => 'New', 'last_name' => 'Admin', 'email' => $email, 'status' => 'active', 'roles' => [$adminRole->id],
    ])->assertSessionHasErrors('roles');
    expect(User::query()->where('email', $email)->exists())->toBeFalse();

    // The manager still manages everything else on the account.
    $this->actingAs($manager)->patch(route('court.users.update', $target), acctHardeningForm($target, ['status' => 'inactive', 'roles' => []]))
        ->assertSessionHasNoErrors()->assertRedirect(route('court.users.index'));
    expect($target->fresh()->status)->toBe('inactive');

    $this->actingAs(acctHardeningAdmin())
        ->patch(route('court.users.update', $target), acctHardeningForm($target, ['status' => 'active', 'roles' => [$adminRole->id]]))
        ->assertSessionHasNoErrors();
    expect($target->roles()->pluck('court_roles.id')->all())->toBe([$adminRole->id]);
});

test('only an administrator can delete an administrator account', function (): void {
    $manager = acctHardeningUser(acctHardeningRole('user-manager', ['users.manage']));
    $otherAdmin = acctHardeningAdmin();
    $clerk = acctHardeningUser(acctHardeningRole('clerk'));

    $this->actingAs($manager)->get(route('court.users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data', fn ($rows): bool => collect($rows)->firstWhere('id', $otherAdmin->id)['can']['delete'] === false
                && collect($rows)->firstWhere('id', $clerk->id)['can']['delete'] === true));

    $this->actingAs($manager)->delete(route('court.users.destroy', $otherAdmin))->assertForbidden();
    expect(User::query()->whereKey($otherAdmin->id)->exists())->toBeTrue();

    $this->actingAs($manager)->delete(route('court.users.destroy', $clerk))->assertRedirect(route('court.users.index'));
    expect(User::query()->whereKey($clerk->id)->exists())->toBeFalse();

    $this->actingAs(acctHardeningAdmin())->delete(route('court.users.destroy', $otherAdmin))->assertRedirect(route('court.users.index'));
    expect(User::query()->whereKey($otherAdmin->id)->exists())->toBeFalse();
});

test('clearing every role on an account works although the form then sends no roles field', function (): void {
    $target = acctHardeningUser(acctHardeningRole('clerk'), acctHardeningRole('registrar'));

    // Multipart cannot carry an empty list: the form sends no `roles` at all.
    $this->actingAs(acctHardeningAdmin())
        ->patch(route('court.users.update', $target), acctHardeningForm($target, ['status' => 'active']))
        ->assertSessionHasNoErrors()->assertRedirect(route('court.users.index'));

    expect($target->roles()->count())->toBe(0);
});

// ---------------------------------------------------------------- teams

test('a team cannot be made to report to itself or to one of its sub-teams', function (): void {
    $manager = acctHardeningUser(acctHardeningRole('team-manager', ['teams.manage']));
    $top = Team::query()->create(['name' => 'Registry']);
    $middle = Team::query()->create(['name' => 'Bench', 'parent_id' => $top->id]);
    $bottom = Team::query()->create(['name' => 'Clerks', 'parent_id' => $middle->id]);
    $elsewhere = Team::query()->create(['name' => 'Archive']);

    $this->actingAs($manager)->get(route('court.teams.edit', $top))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teams', fn ($teams): bool => collect($teams)->pluck('id')->all() === [$elsewhere->id]));

    foreach ([$top, $middle, $bottom] as $parent) {
        $this->actingAs($manager)->from(route('court.teams.edit', $top))
            ->patch(route('court.teams.update', $top), ['name' => 'Registry', 'parent_id' => $parent->id])
            ->assertSessionHasErrors(['parent_id' => __('court::teams.errors.parent_cycle')]);
    }
    expect($top->fresh()->parent_id)->toBeNull();

    $this->actingAs($manager)->patch(route('court.teams.update', $top), ['name' => 'Registry', 'parent_id' => $elsewhere->id])
        ->assertSessionHasNoErrors()->assertRedirect(route('court.teams.index'));
    expect((int) $top->fresh()->parent_id)->toBe($elsewhere->id);
});

// ---------------------------------------------------------------- permissions

test('the permissions list never pages more than 100 rows at once', function (): void {
    $manager = acctHardeningUser(acctHardeningRole('permission-manager', ['permissions.manage']));

    $this->actingAs($manager)->get(route('court.permissions.index', ['perPage' => 100000]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.perPage', 100)->where('permissions.per_page', 100));

    $this->actingAs($manager)->get(route('court.permissions.index', ['perPage' => 0]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.perPage', 5));
});

// ---------------------------------------------------------------- roles

test('the admin role and roles still held by staff cannot be deleted, and the list says why', function (): void {
    $manager = acctHardeningUser(acctHardeningRole('role-manager', ['roles.manage']));
    $admin = acctHardeningRole('admin');
    $held = acctHardeningRole('clerk');
    acctHardeningUser($held);
    $unused = acctHardeningRole('unused');

    $this->actingAs($manager)->get(route('court.roles.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roles.data', fn ($rows): bool => collect($rows)->firstWhere('id', $admin->id)['delete_blocked'] === 'admin'
                && collect($rows)->firstWhere('id', $held->id)['delete_blocked'] === 'assigned'
                && collect($rows)->firstWhere('id', $unused->id)['delete_blocked'] === null));

    $this->actingAs($manager)->from(route('court.roles.index'))->delete(route('court.roles.destroy', $admin))
        ->assertRedirect(route('court.roles.index'))
        ->assertSessionHas('error', ['key' => 'court::roles.index.cannot_delete_admin', 'replace' => []]);
    $this->actingAs($manager)->from(route('court.roles.index'))->delete(route('court.roles.destroy', $held))
        ->assertSessionHas('error', ['key' => 'court::roles.index.cannot_delete_assigned', 'replace' => ['count' => 1]]);
    $this->actingAs($manager)->from(route('court.roles.index'))->delete(route('court.roles.destroy', $unused))
        ->assertSessionHas('ok');

    expect(Role::query()->whereKey([$admin->id, $held->id])->count())->toBe(2)
        ->and(Role::query()->whereKey($unused->id)->exists())->toBeFalse();
});
