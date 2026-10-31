<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * The staff dashboard: case statistics (counts, workload by person and team,
 * recent case titles) only for cases.view, service health and storage only
 * for settings.manage, and the JSON statistics endpoint behind cases.view.
 */

function dashHardeningStaff(array $permissions): User
{
    $role = Role::query()->create(['name' => 'dash-'.Str::lower(Str::random(8))]);
    $role->permissions()->sync(collect($permissions)->map(
        fn (string $permission): int => Permission::query()->firstOrCreate(['name' => $permission], ['label' => Str::headline($permission)])->id,
    )->all());
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->sync([$role->id]);

    return $user;
}

beforeEach(fn () => Cache::flush());

test('staff without cases.view or settings.manage get the dashboard without figures', function (): void {
    $staff = dashHardeningStaff([]);

    $this->actingAs($staff)->get(route('court.admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Dashboard')
            ->where('dashboard', null)
            ->where('serviceHealth', null)
            ->where('dataStorage', null));

    // A period does not bring the figures back either.
    $this->actingAs($staff)->get(route('court.admin.dashboard', ['range' => '30d']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('dashboard', null));

    $this->actingAs($staff)->getJson(route('court.admin.dashboard.stats'))->assertForbidden();
});

test('cases.view brings the case figures and settings.manage the system panels', function (): void {
    $this->actingAs(dashHardeningStaff(['cases.view']))->get(route('court.admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('dashboard.kpis')
            ->where('serviceHealth', null)
            ->where('dataStorage', null));

    $this->actingAs(dashHardeningStaff(['settings.manage']))->get(route('court.admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dashboard', null)
            ->has('serviceHealth.database')
            ->has('dataStorage.tables'));

    $this->actingAs(dashHardeningStaff(['cases.view']))->getJson(route('court.admin.dashboard.stats', ['range' => '30d']))
        ->assertOk()
        ->assertJsonStructure(['kpis']);
});
