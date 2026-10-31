<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('a request to appeal is recorded, shown, and the record released on the React pages', function (): void {
    $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $admin->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);
    $type = CaseType::query()->create(['name' => 'Appeal type '.Str::random(4)]);
    $case = CourtCase::query()->create(['case_number' => 'AP-'.Str::upper(Str::random(8)), 'title' => 'Appealed case', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'closed', 'respondent_name' => 'Bureau of Finance']);
    $open = CourtCase::query()->create(['case_number' => 'AP-'.Str::upper(Str::random(8)), 'title' => 'Open case', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'active']);
    Decision::query()->create(['court_case_id' => $case->id, 'case_number' => $case->case_number, 'decision_date' => now()->toDateString(), 'name' => 'Judgment', 'status' => 'published', 'approved_at' => now()]);

    // Only closed cases with a published decision may be appealed.
    $this->actingAs($admin)->get(route('court.appeals.create', ['case' => $case->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Appeals/Form')->where('appeal', null)->where('caseId', $case->id)
            ->where('cases', fn ($cases) => collect($cases)->pluck('id')->all() === [$case->id]));
    $this->actingAs($admin)->from(route('court.appeals.create'))->post(route('court.appeals.store'), ['court_case_id' => $open->id, 'appellant_type' => 'applicant', 'appellant_name' => 'X', 'grounds' => 'Y'])
        ->assertSessionHasErrors('case');

    $this->actingAs($admin)->post(route('court.appeals.store'), ['court_case_id' => $case->id, 'appellant_type' => 'respondent', 'appellant_name' => 'Bureau of Finance', 'grounds' => 'Procedure.'])->assertRedirect();
    $id = (int) DB::table('court_appeals')->where('court_case_id', $case->id)->value('id');

    $this->actingAs($admin)->get(route('court.appeals.show', $id))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Court/Appeals/Show')->where('appeal.status', 'submitted')->where('appeal.case_number', $case->case_number)
            ->where('appeal.side', 'respondent')->where('appeal.appellant', 'Bureau of Finance')->where('appeal.via', 'registry')->where('can.decide', true));

    $this->actingAs($admin)->post(route('court.appeals.decide', $id), ['decision' => 'approved', 'decision_notes' => 'Granted.'])->assertRedirect();
    $this->actingAs($admin)->get(route('court.appeals.show', $id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('appeal.status', 'approved')->where('appeal.decision_notes', 'Granted.')->where('appeal.decided_by', $admin->name)
            ->where('appeal.released_at', fn ($value) => $value !== null));
    expect(DB::table('court_cases')->where('id', $case->id)->value('appealed_at'))->not->toBeNull();
});
