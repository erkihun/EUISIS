<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function miscAdmin(): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);

    return $user;
}

function miscCase(?CaseType $type = null): CourtCase
{
    $type ??= CaseType::query()->create(['name' => 'Misc '.Str::random(4)]);

    return CourtCase::query()->create(['case_number' => 'MS-'.Str::upper(Str::random(8)), 'title' => 'Misc case', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'pending']);
}

test('the case types list counts the cases using each type and searches by name', function (): void {
    $admin = miscAdmin();
    $type = CaseType::query()->create(['name' => 'Commercial '.Str::random(3)]);
    miscCase($type);

    $this->actingAs($admin)->get(route('court.case-types.index', ['q' => $type->name]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/CaseTypes/Index')
            ->where('filters.q', $type->name)
            ->where('types.total', 1)
            ->where('types.data.0.cases_count', 1));
});

test('the appeals list shows each appeal with its case and state', function (): void {
    $admin = miscAdmin();
    $case = miscCase();
    $id = DB::table('court_appeals')->insertGetId(['court_case_id' => $case->id, 'submitted_by_user_id' => $admin->id, 'appeal_number' => 'AP-'.Str::upper(Str::random(6)), 'title' => 'Contest the decision', 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($admin)->get(route('court.appeals.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Appeals/Index')
            ->where('appeals.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $id && $row['case_number'] === $case->case_number && $row['status'] === 'submitted')));
});

test('the notifications page lists what awaits the person and marks an item seen', function (): void {
    $admin = miscAdmin();
    $case = miscCase();

    $this->actingAs($admin)->get(route('court.admin.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Notifications/Index')
            ->where('cases.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $case->id && $row['case_number'] === $case->case_number))
            ->has('messages.data')
            ->has('hearings.data')
            ->has('respondentViews.data'));

    $this->actingAs($admin)->post(route('court.admin.notifications.markOne'), ['type' => 'case', 'sourceId' => $case->id])->assertRedirect();
    $this->actingAs($admin)->get(route('court.admin.notifications.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('court::cases.total', 0));
});
