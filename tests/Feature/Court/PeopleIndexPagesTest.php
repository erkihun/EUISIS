<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function peopleAdmin(): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);

    return $user;
}

test('the applicants list shows each applicant, filters by state and lets a manager change it', function (): void {
    $admin = peopleAdmin();
    $active = Applicant::query()->create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'email' => 'abebe-'.Str::random(5).'@example.com', 'phone' => '+251911000001', 'national_id_number' => (string) random_int(1000000000, 9999999999), 'password' => bcrypt('secret-password-1'), 'is_active' => true]);
    $inactive = Applicant::query()->create(['first_name' => 'Sara', 'last_name' => 'Tesfaye', 'email' => 'sara-'.Str::random(5).'@example.com', 'phone' => '+251911000002', 'national_id_number' => (string) random_int(1000000000, 9999999999), 'password' => bcrypt('secret-password-1'), 'is_active' => false]);

    $this->actingAs($admin)->get(route('court.applicants.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Applicants/Index')
            ->where('canManage', true)
            ->where('applicants.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $active->id && $row['full_name'] === $active->full_name && $row['is_active'] === true)));

    $this->actingAs($admin)->get(route('court.applicants.index', ['status' => 'inactive']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.status', 'inactive')->where('applicants.data.0.id', $inactive->id));

    $this->actingAs($admin)->patch(route('court.applicants.status.update', $inactive), ['is_active' => 1])->assertRedirect();
    expect($inactive->fresh()->is_active)->toBeTrue();
});

test('the users list shows each account with its roles and what the viewer may do', function (): void {
    $admin = peopleAdmin();
    $clerk = User::factory()->create(['status' => 'inactive', 'name' => 'Clerk Person']);
    $clerk->roles()->sync([Role::query()->firstOrCreate(['name' => 'clerk'])->id]);

    $this->actingAs($admin)->get(route('court.users.index', ['q' => 'Clerk']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Users/Index')
            ->where('filters.q', 'Clerk')
            ->where('users.total', 1)
            ->where('users.data.0.name', 'Clerk Person')
            ->where('users.data.0.status', 'inactive')
            ->where('users.data.0.roles', ['clerk'])
            ->where('users.data.0.can.delete', true));
});
