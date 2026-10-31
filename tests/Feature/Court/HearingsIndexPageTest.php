<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('the hearings list shows each hearing with its case and filters by case number and date', function (): void {
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);
    $type = CaseType::query()->create(['name' => 'Hearing type '.Str::random(4)]);
    $case = CourtCase::query()->create(['case_number' => 'HR-'.Str::upper(Str::random(8)), 'title' => 'Hearing case', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'active']);
    $other = CourtCase::query()->create(['case_number' => 'HX-'.Str::upper(Str::random(8)), 'title' => 'Other case', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'active']);
    DB::table('court_case_hearings')->insert([
        ['case_id' => $case->id, 'hearing_at' => now()->addDays(2), 'type' => 'Preliminary', 'location' => 'Room 4', 'notes' => 'Bring the file', 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ['case_id' => $other->id, 'hearing_at' => now()->addDays(9), 'type' => 'Final', 'location' => null, 'notes' => null, 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($user)->get(route('court.admin.hearings.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Hearings/Index')
            ->where('hearings.total', 2)
            ->where('hearings.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['case_number'] === $case->case_number && $row['type'] === 'Preliminary' && $row['location'] === 'Room 4' && $row['creator_name'] === $user->name)));

    $this->actingAs($user)->get(route('court.admin.hearings.index', ['case_number' => $case->case_number]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hearings.total', 1)->where('filters.case_number', $case->case_number));

    $this->actingAs($user)->get(route('court.admin.hearings.index', ['hearing_date' => now()->addDays(9)->toDateString()]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hearings.total', 1)->where('hearings.data.0.case_number', $other->case_number));
});
