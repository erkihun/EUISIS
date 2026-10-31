<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Decision;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\Team;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function dashboardResolvedCase(string $status, User $assignee): CourtCase
{
    $caseType = CaseType::query()->create([
        'name' => 'Dashboard resolved '.Str::uuid(),
    ]);

    return CourtCase::query()->create([
        'case_number' => 'DR-'.Str::upper(Str::random(10)),
        'title' => 'Dashboard resolved case',
        'case_type_id' => $caseType->id,
        'filing_date' => now()->toDateString(),
        'status' => $status,
        'assigned_member_user_id' => $assignee->id,
    ]);
}

function dashboardDecision(CourtCase $courtCase, string $status): Decision
{
    return Decision::query()->create([
        'court_case_id' => $courtCase->id,
        'case_number' => $courtCase->case_number,
        'decision_date' => now()->toDateString(),
        'name' => 'Dashboard decision',
        'decision_content' => 'Decision content.',
        'status' => $status,
    ]);
}

test('published decisions define resolved cases across the admin dashboard', function (): void {
    Cache::flush();

    // Case statistics are shown to staff who can view cases.
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::query()->create(['name' => 'dashboard-reader']);
    $role->permissions()->sync([Permission::query()->firstOrCreate(['name' => 'cases.view'], ['label' => 'View cases'])->id]);
    $user->roles()->sync([$role->id]);
    $team = Team::query()->create(['name' => 'Dashboard team']);
    $team->users()->attach($user);

    $activeWithPublishedDecision = dashboardResolvedCase('active', $user);
    dashboardDecision($activeWithPublishedDecision, 'published');
    dashboardDecision($activeWithPublishedDecision, 'published');

    $pendingWithPublishedDecision = dashboardResolvedCase('pending', $user);
    dashboardDecision($pendingWithPublishedDecision, 'published');

    $closedWithDraftDecision = dashboardResolvedCase('closed', $user);
    dashboardDecision($closedWithDraftDecision, 'draft');

    $this->actingAs($user)
        ->get(route('court.admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Dashboard')
            ->where('dashboard.kpis.resolved', 2)
            ->where('dashboard.members', fn ($members) => collect($members)->firstWhere('id', $user->id)['resolved'] === 2));

    $this->actingAs($user)
        ->getJson(route('court.admin.dashboard.stats', [
            'start' => now()->subDay()->toDateString(),
            'end' => now()->addDay()->toDateString(),
        ]))
        ->assertOk()
        ->assertJsonPath('kpis.resolvedCases', 2)
        ->assertJsonPath('memberCaseCounts.0.resolved_cases_count', 2);
});
