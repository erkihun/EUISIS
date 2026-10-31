<?php

declare(strict_types=1);

/*
 * Shared set-up for the *HardeningTest files that cover the case, record,
 * decision, bench note, letter, appeal, template and hearing endpoints.
 * Required with require_once; the `jw` prefix keeps the names apart from the
 * other test files' helpers (Pest loads every test file into one namespace).
 */

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;

/** An active, verified staff user holding exactly `$permissions` (role 'admin' grants everything). */
function jwStaff(array $permissions, ?string $roleName = null): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName ?? 'jw-'.Str::uuid()]);
    $role->permissions()->syncWithoutDetaching(collect($permissions)->map(
        fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id,
    ));

    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->sync([$role->id]);

    return $user;
}

/** An accepted, active case; `$attributes` override any column. */
function jwCase(array $attributes = []): CourtCase
{
    $type = CaseType::query()->create(['name' => 'JW '.Str::random(6)]);

    return CourtCase::query()->create(array_merge([
        'case_number' => 'JW-'.Str::upper(Str::random(8)),
        'title' => 'Almaz Tadesse',
        'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(),
        'status' => 'active',
        'review_status' => 'accepted',
        'respondent_name' => 'Bole Sub-city',
    ], $attributes));
}

/** A team with `$members` and no leader: its members are "plain team members" scoped to their own cases. */
function jwTeam(User ...$members): Team
{
    $team = Team::query()->create(['name' => 'JW team '.Str::random(6)]);
    $team->users()->attach(collect($members)->map(fn (User $member): int => $member->id)->all());

    return $team;
}
