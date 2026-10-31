<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function decisionUiOrderRole(): Role
{
    $role = Role::query()->create(['name' => 'decision-ui-order-'.Str::uuid()]);
    $permissionIds = collect([
        'decision.view',
        'decision.view.all',
        'decision.create',
    ])->map(fn (string $name): int => Permission::query()->create([
        'name' => $name,
        'label' => Str::headline($name),
    ])->id);

    $role->permissions()->sync($permissionIds);

    return $role;
}

function decisionUiOrderUser(Role $role, string $name): User
{
    $user = User::factory()->create(['name' => $name, 'status' => 'active']);
    $user->roles()->attach($role);

    return $user;
}

function decisionUiOrderCase(): CourtCase
{
    $caseType = CaseType::query()->create(['name' => 'Judge UI order test']);

    return CourtCase::query()->create([
        'case_number' => 'JU-'.Str::upper(Str::random(10)),
        'title' => 'Judge UI order case',
        'case_type_id' => $caseType->id,
        'filing_date' => now()->toDateString(),
    ]);
}

test('create form seats the session judge in the middle slot while the page shows that seat first', function (): void {
    $role = decisionUiOrderRole();
    $creator = decisionUiOrderUser($role, 'Session Judge');

    // The React form draws the seats in the order [1, 0, 2]: the middle seat (storage index 1) is shown first and is the signed-in judge.
    $this->actingAs($creator)
        ->get(route('court.decisions.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Decisions/Create')
            ->where('decision', null)
            ->where('currentUser.id', $creator->id)
            ->where('currentUser.name', 'Session Judge')
            ->where('defaults.judges', [null, $creator->id, null])
            ->etc());
});

test('decision view always displays the decision author first without changing stored panel order', function (): void {
    Storage::fake('court_public');
    $role = decisionUiOrderRole();
    $author = decisionUiOrderUser($role, 'Decision Author');
    $author->update(['signature_path' => 'signatures/author-ui-hidden.png']);
    Storage::disk('court_public')->put($author->signature_path, 'signature');
    $viewer = decisionUiOrderUser($role, 'Different Viewer');
    $firstStoredJudge = decisionUiOrderUser($role, 'First Stored Judge');
    $thirdJudge = decisionUiOrderUser($role, 'Third Judge');
    $courtCase = decisionUiOrderCase();
    $storedPanel = [
        ['order' => 1, 'admin_user_id' => $firstStoredJudge->id, 'admin_user_name' => $firstStoredJudge->name],
        ['order' => 2, 'admin_user_id' => $author->id, 'admin_user_name' => $author->name],
        ['order' => 3, 'admin_user_id' => $thirdJudge->id, 'admin_user_name' => $thirdJudge->name],
    ];
    $decision = Decision::query()->create([
        'court_case_id' => $courtCase->id,
        'case_number' => $courtCase->case_number,
        'decision_date' => now()->toDateString(),
        'panel_judges' => $storedPanel,
        'reviewing_admin_user_id' => $author->id,
        'reviewing_admin_user_name' => $author->name,
        'name' => 'UI order ruling',
        'decision_content' => 'Decision content.',
        'status' => 'published',
    ]);

    $this->actingAs($viewer)
        ->get(route('court.decisions.show', $decision))
        ->assertOk()
        ->assertDontSee(Storage::disk('court_public')->url($author->signature_path))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Decisions/Show')
            ->where('decision.panel', fn ($panel) => collect($panel)->pluck('name')->all() === ['Decision Author', 'First Stored Judge', 'Third Judge']
                && collect($panel)->pluck('number')->all() === [1, 2, 3])
            ->etc());

    expect($decision->fresh()->panel_judges)->toBe($storedPanel);
});
