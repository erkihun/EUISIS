<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function accessAdmin(): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);

    return $user;
}

test('the teams list shows each team with its parent, members and leader', function (): void {
    $admin = accessAdmin();
    $parent = Team::query()->create(['name' => 'Registry']);
    $team = Team::query()->create(['name' => 'Bench A', 'parent_id' => $parent->id, 'team_leader_id' => $admin->id, 'description' => 'First bench']);
    $team->users()->attach($admin);

    $this->actingAs($admin)->get(route('court.teams.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Teams/Index')
            ->where('teams', fn ($teams) => collect($teams)->contains(fn ($row) => $row['name'] === 'Bench A' && $row['parent_name'] === 'Registry' && $row['members_count'] === 1 && $row['leader_name'] === $admin->name)));
});

test('the roles list shows each role with its permissions and member count', function (): void {
    $admin = accessAdmin();
    $role = Role::query()->create(['name' => 'Clerk '.Str::random(4), 'description' => 'Files cases']);
    $role->permissions()->sync([Permission::query()->firstOrCreate(['name' => 'cases.view'], ['label' => 'View cases'])->id]);

    $this->actingAs($admin)->get(route('court.roles.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Roles/Index')
            ->where('roles.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $role->id && $row['permissions'] === ['cases.view'] && $row['users_count'] === 0)));
});

test('the permissions list searches by name and counts the roles and people holding each', function (): void {
    $admin = accessAdmin();
    $permission = Permission::query()->firstOrCreate(['name' => 'letters.approve'], ['label' => 'Approve letters']);
    $role = Role::query()->create(['name' => 'Approver '.Str::random(4)]);
    $role->permissions()->sync([$permission->id]);
    $role->users()->attach($admin);

    $this->actingAs($admin)->get(route('court.permissions.index', ['q' => 'letters.approve']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Permissions/Index')
            ->where('filters.q', 'letters.approve')
            ->where('permissions.total', 1)
            ->where('permissions.data.0.roles', fn ($roles) => collect($roles)->contains($role->name))
            ->where('permissions.data.0.users_count', 1));
});
