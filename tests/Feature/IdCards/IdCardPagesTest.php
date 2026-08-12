<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function createPageTestCard(int $sequence, CardStatus $status = CardStatus::Active): IdCard
{
    $employee = Employee::query()->create([
        'employee_number' => sprintf('EMP-PAGE-%03d', $sequence),
        'full_name' => "Page Employee {$sequence}",
        'first_name' => 'Page',
        'last_name' => "Employee {$sequence}",
        'status' => EmployeeStatus::Active,
    ]);

    $organizationType = OrganizationType::query()->firstOrCreate(
        ['code' => 'ID-CARD-PAGE-TEST'],
        ['name_en' => 'ID Card Page Test'],
    );
    $organization = Organization::query()->firstOrCreate(
        ['code' => 'ID-CARD-PAGE-ORG'],
        ['organization_type_id' => $organizationType->id, 'name_en' => 'ID Card Page Organization', 'status' => OrganizationStatus::Active],
    );
    $hierarchyVersion = HierarchyVersion::query()->firstOrCreate(
        ['version_name' => 'id-card-page-test'],
        ['status' => HierarchyVersionStatus::Published],
    );
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'hierarchy_version_id' => $hierarchyVersion->id,
        'assignment_status' => AssignmentStatus::Active,
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);

    return IdCard::query()->create([
        'employee_id' => $employee->id,
        'card_number' => sprintf('IDC-PAGE-%03d', $sequence),
        'status' => $status,
        'issued_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'token_version' => 0,
        'is_current' => true,
    ]);
}

beforeEach(function (): void {
    foreach (['cards.view', 'id-cards.printAnytime', 'id-cards.exportPng'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

it('renders a server-paginated ID card index with filters and summaries', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('cards.view');

    foreach (range(1, 26) as $sequence) {
        createPageTestCard($sequence);
    }

    $this->actingAs($user)
        ->get(route('id-cards.index', ['search' => 'IDC-PAGE']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('IdCards/Index')
            ->has('cards.data', 25)
            ->where('cards.meta.total', 26)
            ->where('summary.total', 26)
            ->where('filters.search', 'IDC-PAGE'));
});

it('renders ID card show and preview pages without rotating QR identity', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['cards.view', 'id-cards.printAnytime', 'id-cards.exportPng']);
    $card = createPageTestCard(100);
    $tokenVersion = $card->token_version;

    $this->actingAs($user)
        ->get(route('id-cards.show', $card))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('IdCards/Show'));

    $this->actingAs($user)
        ->get(route('id-cards.preview', $card))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('IdCards/Preview')
            ->has('card.employee'));

    expect($card->refresh()->token_version)->toBe($tokenVersion);
});

it('denies ID card pages to users without card permissions', function (): void {
    $user = User::factory()->create();
    $card = createPageTestCard(200);

    $this->actingAs($user)->get(route('id-cards.index'))->assertForbidden();
    $this->actingAs($user)->get(route('id-cards.show', $card))->assertForbidden();
});
