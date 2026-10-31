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


