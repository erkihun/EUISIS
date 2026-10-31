<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('the system report counts the records, breaks them down and narrows the filtered window', function (): void {
    $admin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $admin->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => 'admin'])->id]);
    $type = CaseType::query()->create(['name' => 'Report type '.Str::random(4)]);
    CourtCase::query()->create(['case_number' => 'RP-'.Str::upper(Str::random(8)), 'title' => 'Reported case', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'pending']);
    CourtCase::query()->create(['case_number' => 'RP-'.Str::upper(Str::random(8)), 'title' => 'Active case', 'case_type_id' => $type->id, 'filing_date' => now()->toDateString(), 'status' => 'active']);

    $this->actingAs($admin)->get(route('court.reports.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Reports/Index')
            ->where('summaryCards', fn ($cards) => collect($cards)->firstWhere('key', 'cases_total')['value'] === 2)
            ->where('breakdowns.cases_status', fn ($rows) => collect($rows)->sum('total') === 2)
            ->where('filters.status', 'active')
            ->where('filtered.count', 1)
            ->has('recentCases', 2)
            ->has('judges'));
});
