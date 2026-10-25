<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/*
 * Core Stage 6 query budgets: the busiest Golden Path lists run a fixed number
 * of queries whatever the page size (no N+1). Ten more rows must not add a
 * query.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'QB-T', 'name_en' => 'Budget type']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'QB-ORG', 'name_en' => 'Budget bureau', 'status' => 'active']);
    $this->seq = 0;

    foreach (['employees.view', 'employees.viewAny', 'cards.view', 'id-cards.viewAny', 'id-cards.view', 'positions.viewAny', 'positions.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->viewer = User::factory()->create();
    $this->viewer->givePermissionTo(['employees.view', 'employees.viewAny', 'cards.view', 'id-cards.viewAny', 'id-cards.view', 'positions.viewAny', 'positions.view']);
});

function budgetPlacements($test, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $n = ++$test->seq;
        $position = Position::query()->create(['organization_id' => $test->organization->id, 'job_position_code' => 'QB-P'.$n, 'title_en' => 'Officer '.$n, 'is_active' => true]);
        $employee = Employee::query()->create(['employee_number' => 'QB-E'.$n, 'first_name' => 'Budget', 'last_name' => 'Row'.$n, 'full_name' => 'Budget Row'.$n, 'status' => EmployeeStatus::Active->value]);
        $assignment = EmployeeAssignment::query()->create(['employee_id' => $employee->id, 'organization_id' => $test->organization->id, 'position_id' => $position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-01-01']);
        $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
        IdCard::query()->create(['employee_id' => $employee->id, 'card_number' => 'QB-C'.$n, 'status' => CardStatus::Active->value, 'is_current' => true, 'issued_at' => now()->subMonth(), 'expires_at' => now()->addYear()]);
    }
}

function budgetQueries($test, string $url): int
{
    $test->actingAs($test->viewer);
    // A first request warms per-process caches (permissions, settings, translations).
    $test->get($url)->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $test->get($url)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('keeps the employee, ID card and position lists to a fixed number of queries', function (string $route, array $parameters): void {
    budgetPlacements($this, 2);
    $url = fn (): string => route($route, $parameters === ['org'] ? ['organization_id' => $this->organization->id] : $parameters);
    $few = budgetQueries($this, $url());

    budgetPlacements($this, 10);
    $many = budgetQueries($this, $url());

    expect($many)->toBe($few);
})->with([
    'employees' => ['employees.index', []],
    'id cards' => ['id-cards.index', []],
    'positions' => ['positions.index', ['org']],
]);
