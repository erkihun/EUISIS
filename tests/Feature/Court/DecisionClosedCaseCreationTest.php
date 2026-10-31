<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;

function closedCaseDecisionCreator(): User
{
    $permission = Permission::query()->create([
        'name' => 'decision.create',
        'label' => 'Create decisions',
    ]);
    $role = Role::query()->create([
        'name' => 'closed-case-decision-creator-'.Str::uuid(),
    ]);
    $role->permissions()->attach($permission);

    $user = User::factory()->create(['status' => 'active']);
    $user->roles()->attach($role);

    return $user;
}

function caseForDecisionCreation(CaseType $caseType, string $status): CourtCase
{
    return CourtCase::query()->create([
        'case_number' => 'DC-'.Str::upper(Str::random(10)),
        'title' => Str::headline($status).' decision case',
        'case_type_id' => $caseType->id,
        'filing_date' => now()->toDateString(),
        'status' => $status,
    ]);
}

test('a decision can only be created for a closed case', function (): void {
    $creator = closedCaseDecisionCreator();
    $caseType = CaseType::query()->create(['name' => 'Decision creation test']);
    $closedCase = caseForDecisionCreation($caseType, 'closed');
    $activeCase = caseForDecisionCreation($caseType, 'active');

    $this->actingAs($creator)
        ->get(route('court.decisions.create'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Court/Decisions/Create')
            ->where('cases', fn ($cases) => collect($cases)->pluck('case_number')->contains($closedCase->case_number)
                && ! collect($cases)->pluck('case_number')->contains($activeCase->case_number))
            ->etc());

    $this->actingAs($creator)
        ->post(route('court.decisions.store'), [
            'case_id' => $activeCase->id,
            'decision_date' => now()->toDateString(),
            'decision_content' => 'Active cases must be rejected.',
        ])
        ->assertSessionHasErrors('case_id');

    $this->assertDatabaseMissing('court_decisions', [
        'court_case_id' => $activeCase->id,
    ]);

    // A decision needs a complete panel, with the creator in the presiding seat.
    $wingJudges = User::factory()->count(2)->create(['status' => 'active']);

    $this->actingAs($creator)
        ->post(route('court.decisions.store'), [
            'case_id' => $closedCase->id,
            'decision_date' => now()->toDateString(),
            'decision_content' => 'Closed cases can receive decisions.',
            'judges' => [
                ['admin_user_id' => $wingJudges[0]->id],
                ['admin_user_id' => $creator->id],
                ['admin_user_id' => $wingJudges[1]->id],
            ],
        ])
        ->assertRedirect(route('court.decisions.index'));

    $this->assertDatabaseHas('court_decisions', [
        'court_case_id' => $closedCase->id,
        'status' => 'draft',
    ]);
});
