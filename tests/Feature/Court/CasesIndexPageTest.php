<?php

declare(strict_types=1);

use Modules\Court\Domain\Models\CaseType;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\Permission;
use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function casesIndexUser(array $permissions): User
{
    $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    $role = Role::query()->create(['name' => 'Cases '.Str::random(6)]);
    $role->permissions()->sync(collect($permissions)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name], ['label' => Str::headline($name)])->id));
    $user->roles()->sync([$role->id]);

    return $user;
}

function casesIndexCase(string $status, string $reviewStatus = 'accepted', ?CaseType $type = null): CourtCase
{
    $type ??= CaseType::query()->create(['name' => 'Type '.Str::random(5)]);

    return CourtCase::query()->create([
        'case_number' => 'CI-'.Str::upper(Str::random(8)),
        'title' => 'Index case '.$status,
        'case_type_id' => $type->id,
        'filing_date' => now()->toDateString(),
        'status' => $status,
        'review_status' => $reviewStatus,
    ]);
}

test('the cases register lists accepted cases with the filters and hides review states from a non-reviewer', function (): void {
    $viewer = casesIndexUser(['cases.view']);
    $type = CaseType::query()->create(['name' => 'Civil '.Str::random(4)]);
    $accepted = casesIndexCase('pending', 'accepted', $type);
    $awaiting = casesIndexCase('active', 'awaiting_review', $type);

    $this->actingAs($viewer)->get(route('court.cases.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Court/Cases/Index')
            ->where('isReviewer', false)
            ->where('canExport', false)
            ->where('statuses', ['pending', 'active', 'adjourned', 'dismissed', 'closed'])
            ->where('cases.data', fn ($rows) => collect($rows)->pluck('case_number')->contains($accepted->case_number)
                && ! collect($rows)->pluck('case_number')->contains($awaiting->case_number))
            ->where('types', fn ($types) => collect($types)->pluck('name')->contains($type->name)));

    $this->actingAs($viewer)->get(route('court.cases.index', ['status' => 'active']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'active')
            ->where('cases.data', fn ($rows) => collect($rows)->pluck('case_number')->doesntContain($accepted->case_number)));
});

test('a reviewer sees the review state and the exporter the export action', function (): void {
    $reviewer = casesIndexUser(['cases.view', 'cases.review', 'reports.export']);
    $awaiting = casesIndexCase('pending', 'awaiting_review');

    $this->actingAs($reviewer)->get(route('court.cases.index', ['q' => $awaiting->case_number]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('isReviewer', true)
            ->where('canExport', true)
            ->where('court::cases.total', 1)
            ->where('cases.data.0.review_status', 'awaiting_review')
            ->where('cases.data.0.filing_date', now()->toDateString()));
});
