<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function accessFormsAdmin(): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);

    return $user;
}

// ---------------------------------------------------------------- users

test('the new user form lists every role and the default password notice, and the page payload creates the account', function (): void {
    $admin = accessFormsAdmin();
    $clerk = Role::query()->create(['name' => 'clerk-'.Str::random(4)]);
    config(['auth.default_user_password' => 'Welcome123!']);

    $this->actingAs($admin)->get(route('court.users.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Users/Create')
            // No default password is shown: store() sets a random one and emails a setup link.
            ->missing('defaultPassword')
            ->where('roles', fn ($roles) => collect($roles)->contains(fn ($row) => $row['id'] === $clerk->id && $row['name'] === $clerk->name)));

    $email = 'abebe-'.Str::lower(Str::random(6)).'@example.com';
    $this->actingAs($admin)->post(route('court.users.store'), [
        'first_name' => 'Abebe',
        'middle_name' => 'Kebede',
        'last_name' => 'Tesfaye',
        'email' => $email,
        'status' => 'inactive',
        'gender' => 'male',
        'date_of_birth' => '1990-05-04',
        'national_id' => '1234 5678 9012 3456',
        'position' => 'Registrar',
        'phone' => '+251911000001',
        'address' => 'Addis Ababa',
        'roles' => [$clerk->id],
        'avatar' => null,
        'signature' => null,
        'stamp' => null,
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.users.index'));

    $created = User::query()->where('email', $email)->firstOrFail();
    expect($created->name)->toBe('Abebe Kebede Tesfaye')
        ->and($created->status)->toBe('inactive')
        ->and($created->national_id)->toBe('1234567890123456')
        ->and($created->date_of_birth?->format('Y-m-d'))->toBe('1990-05-04')
        ->and((bool) $created->account->must_change_password)->toBeTrue()
        ->and($created->roles->pluck('id')->all())->toBe([$clerk->id]);
});

test('the edit user form carries the account split into its name parts with its roles, and the page payload updates it', function (): void {
    $admin = accessFormsAdmin();
    $clerk = Role::query()->create(['name' => 'clerk-'.Str::random(4)]);
    $judge = Role::query()->create(['name' => 'judge-'.Str::random(4)]);
    $user = User::factory()->create(['name' => 'Sara Alemu Bekele', 'status' => 'active', 'gender' => 'female', 'date_of_birth' => '1988-01-15', 'position' => 'Clerk']);
    $user->roles()->sync([$clerk->id]);

    $this->actingAs($admin)->get(route('court.users.edit', $user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Users/Edit')
            ->where('user.id', $user->id)
            ->where('user.first_name', 'Sara')
            ->where('user.middle_name', 'Alemu')
            ->where('user.last_name', 'Bekele')
            ->where('user.email', $user->email)
            ->where('user.gender', 'female')
            ->where('user.date_of_birth', '1988-01-15')
            ->where('user.position', 'Clerk')
            ->where('user.role_ids', [$clerk->id])
            ->where('user.avatar_url', null)
            ->where('roles', fn ($roles) => collect($roles)->pluck('id')->contains($judge->id)));

    $this->actingAs($admin)->patch(route('court.users.update', $user), [
        'first_name' => 'Sara',
        'middle_name' => '',
        'last_name' => 'Bekele',
        'email' => $user->email,
        'status' => 'inactive',
        'gender' => 'female',
        'date_of_birth' => '1988-01-15',
        'national_id' => '',
        'position' => 'Senior Clerk',
        'phone' => '',
        'address' => '',
        'password' => null,
        'password_confirmation' => null,
        'roles' => [$judge->id],
        'remove_avatar' => 0,
        'remove_signature' => 0,
        'remove_stamp' => 0,
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.users.index'));

    $user->refresh();
    expect($user->name)->toBe('Sara Bekele')
        ->and($user->status)->toBe('inactive')
        ->and($user->position)->toBe('Senior Clerk')
        ->and($user->roles->pluck('id')->all())->toBe([$judge->id]);
});

test('the edit user form shows the stored signature and the remove box clears it', function (): void {
    Storage::fake('court_public');
    Storage::disk('court_public')->put('signatures/sig.png', 'png-bytes');
    $admin = accessFormsAdmin();
    $user = User::factory()->create(['name' => 'Dawit Haile', 'status' => 'active', 'signature_path' => 'signatures/sig.png']);

    $this->actingAs($admin)->get(route('court.users.edit', $user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Users/Edit')
            ->where('user.first_name', 'Dawit')
            ->where('user.middle_name', '')
            ->where('user.last_name', 'Haile')
            ->where('user.signature_url', fn ($url) => is_string($url) && str_ends_with($url, 'signatures/sig.png')));

    $this->actingAs($admin)->patch(route('court.users.update', $user), [
        'first_name' => 'Dawit',
        'last_name' => 'Haile',
        'email' => $user->email,
        'status' => 'active',
        'roles' => [],
        'remove_signature' => 1,
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.users.index'));

    expect($user->fresh()->signature_path)->toBeNull();
    Storage::disk('court_public')->assertMissing('signatures/sig.png');
});

test('the user profile page shows who the account is, its roles and whether the viewer may edit it', function (): void {
    $admin = accessFormsAdmin();
    $role = Role::query()->create(['name' => 'registrar-'.Str::random(4)]);
    $user = User::factory()->create(['name' => 'Hanna Girma', 'status' => 'active']);
    $user->roles()->sync([$role->id]);

    $this->actingAs($admin)->get(route('court.users.show', $user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Users/Show')
            ->where('user.id', $user->id)
            ->where('user.name', 'Hanna Girma')
            ->where('user.first_name', 'Hanna')
            ->where('user.last_name', 'Girma')
            ->where('user.email', $user->email)
            ->where('user.status', 'active')
            ->where('user.roles', [$role->name])
            ->where('user.signature_url', null)
            ->where('user.created_at', $user->created_at->toIso8601String())
            ->where('can.update', true));
});

// ---------------------------------------------------------------- teams

test('the new team form offers parents and free leaders, and the page payload creates the team with its leader as member', function (): void {
    $admin = accessFormsAdmin();
    $registry = Team::query()->create(['name' => 'Registry '.Str::random(3)]);
    $taken = User::factory()->create(['status' => 'active']);
    $registry->users()->attach($taken);
    $free = User::factory()->create(['status' => 'active']);

    $this->actingAs($admin)->get(route('court.teams.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Teams/Create')
            ->where('teams', fn ($teams) => collect($teams)->contains(fn ($row) => $row['id'] === $registry->id && $row['name'] === $registry->name))
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($free->id) && ! collect($users)->pluck('id')->contains($taken->id)));

    $name = 'Bench '.Str::random(4);
    $this->actingAs($admin)->post(route('court.teams.store'), [
        'name' => $name,
        'parent_id' => $registry->id,
        'team_leader_id' => $free->id,
        'description' => 'First bench',
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.teams.index'));

    $team = Team::query()->where('name', $name)->firstOrFail();
    expect($team->parent_id)->toBe($registry->id)
        ->and($team->team_leader_id)->toBe($free->id)
        ->and($team->users->pluck('id')->all())->toBe([$free->id]);
});

test('the edit team form carries the team, the other teams and the people it may hold, and the page payloads update details and members', function (): void {
    $admin = accessFormsAdmin();
    $parent = Team::query()->create(['name' => 'Registry '.Str::random(3)]);
    $other = Team::query()->create(['name' => 'Other '.Str::random(3)]);
    $leader = User::factory()->create(['status' => 'active']);
    $member = User::factory()->create(['status' => 'active']);
    $free = User::factory()->create(['status' => 'active']);
    $elsewhere = User::factory()->create(['status' => 'active']);
    $other->users()->attach($elsewhere);
    $team = Team::query()->create(['name' => 'Bench '.Str::random(3), 'parent_id' => $parent->id, 'team_leader_id' => $leader->id]);
    $team->users()->attach([$leader->id, $member->id]);

    $this->actingAs($admin)->get(route('court.teams.edit', $team))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Teams/Edit')
            ->where('team.id', $team->id)
            ->where('team.parent_id', $parent->id)
            ->where('team.team_leader_id', $leader->id)
            ->where('team.user_ids', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$leader->id, $member->id])->sort()->values()->all())
            ->where('teams', fn ($teams) => collect($teams)->pluck('id')->contains($other->id) && ! collect($teams)->pluck('id')->contains($team->id))
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($member->id)
                && collect($users)->pluck('id')->contains($free->id)
                && ! collect($users)->pluck('id')->contains($elsewhere->id)));

    $this->actingAs($admin)->patch(route('court.teams.update', $team), [
        'name' => 'Bench Renamed',
        'parent_id' => null,
        'team_leader_id' => $leader->id,
        'description' => 'Hears first-instance cases',
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.teams.index'));
    $team->refresh();
    expect($team->name)->toBe('Bench Renamed')->and($team->parent_id)->toBeNull()->and($team->description)->toBe('Hears first-instance cases');

    // Members from the page: the leader is unticked but stays; the free person joins; the member leaves.
    $this->actingAs($admin)->patch(route('court.teams.users.update', $team), ['users' => [$free->id]])
        ->assertRedirect(route('court.teams.edit', $team));
    expect($team->users()->pluck('court_staff.id')->sort()->values()->all())->toBe(collect([$leader->id, $free->id])->sort()->values()->all());
});

test('the team page shows its parent, leader, description and members', function (): void {
    $admin = accessFormsAdmin();
    $parent = Team::query()->create(['name' => 'Registry '.Str::random(3)]);
    $leader = User::factory()->create(['status' => 'active', 'name' => 'Lidya Tadesse']);
    $team = Team::query()->create(['name' => 'Bench '.Str::random(3), 'parent_id' => $parent->id, 'team_leader_id' => $leader->id, 'description' => 'Civil bench']);
    $team->users()->attach($leader);

    $this->actingAs($admin)->get(route('court.teams.show', $team))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Teams/Show')
            ->where('team.id', $team->id)
            ->where('team.parent_name', $parent->name)
            ->where('team.leader_name', 'Lidya Tadesse')
            ->where('team.description', 'Civil bench')
            ->where('team.members', [['id' => $leader->id, 'name' => $leader->name, 'email' => $leader->email]]));
});

// ---------------------------------------------------------------- roles

test('the new role form groups the permissions by module, and the page payload creates the role with them', function (): void {
    $admin = accessFormsAdmin();
    $view = Permission::query()->firstOrCreate(['name' => 'cases.view'], ['label' => 'View cases']);
    $decide = Permission::query()->firstOrCreate(['name' => 'decisions.publish'], ['label' => 'Publish decisions']);

    $this->actingAs($admin)->get(route('court.roles.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Roles/Create')
            ->where('permissionsCount', Permission::query()->count())
            ->where('permissionGroups', fn ($groups) => collect($groups)->contains(fn ($group) => $group['key'] === 'cases'
                    && $group['label'] === __('court::roles.groups.cases')
                    && collect($group['permissions'])->contains(fn ($perm) => $perm['id'] === $view->id && $perm['name'] === 'cases.view' && $perm['label'] === 'View cases'))
                && collect($groups)->contains(fn ($group) => $group['key'] === 'decision' && collect($group['permissions'])->pluck('id')->contains($decide->id))));

    $name = 'Publisher '.Str::random(4);
    $this->actingAs($admin)->post(route('court.roles.store'), [
        'name' => $name,
        'description' => 'Publishes decisions',
        'permissions' => [$view->id, $decide->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.roles.index'));

    $role = Role::query()->where('name', $name)->firstOrFail();
    expect($role->description)->toBe('Publishes decisions')
        ->and($role->permissions->pluck('id')->sort()->values()->all())->toBe(collect([$view->id, $decide->id])->sort()->values()->all());
});

test('the edit role form carries the role with its ticked permissions and member count, and the page payload updates it', function (): void {
    $admin = accessFormsAdmin();
    $view = Permission::query()->firstOrCreate(['name' => 'cases.view'], ['label' => 'View cases']);
    $edit = Permission::query()->firstOrCreate(['name' => 'cases.edit'], ['label' => 'Edit cases']);
    $role = Role::query()->create(['name' => 'Clerk '.Str::random(4), 'description' => 'Files cases']);
    $role->permissions()->sync([$view->id]);
    $role->users()->attach($admin);

    $this->actingAs($admin)->get(route('court.roles.edit', $role))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Roles/Edit')
            ->where('role.id', $role->id)
            ->where('role.name', $role->name)
            ->where('role.description', 'Files cases')
            ->where('role.permission_ids', [$view->id])
            ->where('role.users_count', 1)
            ->where('permissionsCount', Permission::query()->count())
            ->where('permissionGroups', fn ($groups) => collect($groups)->flatMap(fn ($group) => collect($group['permissions'])->pluck('id'))->contains($edit->id)));

    $this->actingAs($admin)->patch(route('court.roles.update', $role), [
        'name' => $role->name,
        'description' => null,
        'permissions' => [$edit->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.roles.index'));

    $role->refresh();
    expect($role->description)->toBeNull()->and($role->permissions->pluck('id')->all())->toBe([$edit->id]);
});

// ---------------------------------------------------------------- permissions

test('the new permission form names the locales, and the page payload creates the permission with a text per locale', function (): void {
    $admin = accessFormsAdmin();

    $this->actingAs($admin)->get(route('court.permissions.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Permissions/Create')
            ->where('permission', null)
            ->where('locales', ['en', 'am'])
            ->where('primaryLocale', 'en'));

    $name = 'letters.sign-'.Str::lower(Str::random(4));
    $this->actingAs($admin)->post(route('court.permissions.store'), [
        'name' => $name,
        'label_translations' => ['en' => 'Sign letters', 'am' => 'ደብዳቤ ፈርም'],
        'description_translations' => ['en' => '', 'am' => 'ደብዳቤዎችን ይፈርማል'],
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.permissions.index'));

    $permission = Permission::query()->where('name', $name)->firstOrFail();
    expect($permission->label)->toBe('Sign letters')
        ->and($permission->label_translations)->toBe(['en' => 'Sign letters', 'am' => 'ደብዳቤ ፈርም'])
        ->and($permission->description)->toBe('ደብዳቤዎችን ይፈርማል')
        ->and($permission->description_translations)->toBe(['am' => 'ደብዳቤዎችን ይፈርማል']);
});

test('the edit permission form shows each locale text, falling back to the plain label for the primary locale, and the page payload updates it', function (): void {
    $admin = accessFormsAdmin();
    $permission = Permission::query()->create([
        'name' => 'reports.export-'.Str::lower(Str::random(4)),
        'label' => 'Export reports',
        'description' => 'Downloads report files',
        'description_translations' => ['am' => 'ሪፖርት ፋይሎችን ያወርዳል'],
    ]);

    $this->actingAs($admin)->get(route('court.permissions.edit', $permission))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Permissions/Edit')
            ->where('permission.id', $permission->id)
            ->where('permission.name', $permission->name)
            ->where('permission.label_translations', ['en' => 'Export reports', 'am' => ''])
            ->where('permission.description_translations', ['en' => 'Downloads report files', 'am' => 'ሪፖርት ፋይሎችን ያወርዳል'])
            ->where('locales', ['en', 'am'])
            ->where('primaryLocale', 'en'));

    $this->actingAs($admin)->patch(route('court.permissions.update', $permission), [
        'name' => $permission->name,
        'label_translations' => ['en' => 'Export the reports', 'am' => 'ሪፖርቶችን አውጣ'],
        'description_translations' => ['en' => 'Downloads report files', 'am' => ''],
    ])->assertSessionHasNoErrors()->assertRedirect(route('court.permissions.index'));

    $permission->refresh();
    expect($permission->label)->toBe('Export the reports')
        ->and($permission->label_translations)->toBe(['en' => 'Export the reports', 'am' => 'ሪፖርቶችን አውጣ'])
        ->and($permission->description_translations)->toBe(['en' => 'Downloads report files']);
});
