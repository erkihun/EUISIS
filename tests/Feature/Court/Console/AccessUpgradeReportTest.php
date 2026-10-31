<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('the access report lists affected roles with their missing permissions and changes nothing', function (): void {
    $grant = fn (Role $role, array $names) => $role->permissions()->sync(collect($names)->map(
        fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id,
    ));

    $clerk = Role::query()->create(['name' => 'clerk']);
    $grant($clerk, ['cases.view', 'cases.edit', 'hearing.create']);
    User::factory()->count(2)->create(['status' => 'active'])->each(fn (User $user) => $user->roles()->attach($clerk));

    $judge = Role::query()->create(['name' => 'judge']);
    $grant($judge, ['bench-notes.manage', 'bench-notes.view', 'bench-notes.create', 'bench-notes.update', 'bench-notes.delete']);

    Role::query()->create(['name' => 'admin']);
    $before = DB::table('court_permission_role')->count();

    Artisan::call('court:access-report', ['--json' => true]);
    $findings = collect(json_decode(Artisan::output(), true))->keyBy('key');

    expect(collect($findings['case_actions']['roles'])->firstWhere('role', 'clerk'))
        ->toMatchArray(['activeUsers' => 2, 'missing' => ['hearing.update', 'hearing.delete', 'file.create', 'file.delete', 'message.create']])
        ->and(collect($findings['dashboard_cases']['roles'])->pluck('role')->all())->toBe(['judge'])
        ->and($findings['bench_notes']['roles'])->toBe([])
        ->and(collect($findings->flatMap(fn (array $finding) => $finding['roles']))->pluck('role'))->not->toContain('admin')
        ->and(DB::table('court_permission_role')->count())->toBe($before);

    $this->artisan('court:access-report')->assertSuccessful();
});
