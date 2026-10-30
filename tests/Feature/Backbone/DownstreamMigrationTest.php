<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Modules\Cafeteria\Application\Actions\ArchiveEmployeeCafeteriaExclusionAction;
use Modules\Cafeteria\Application\Actions\CreateEmployeeCafeteriaExclusionAction;
use Modules\Cafeteria\Application\Actions\EndEmployeeCafeteriaExclusionAction;
use Modules\Cafeteria\Application\Services\CafeteriaEmployeeContext;

/*
 * Stage 3 of the Core migration: service modules read Golden Path facts
 * through Core contracts. The cafeteria exclusion actions now take the
 * employee's organization from the Core placement (CafeteriaEmployeeContext →
 * AssignmentContextReader). Before, they read an `organization_id` the
 * Employee model does not have, so every exclusion audit row lost its
 * organization and scoped auditors never saw it.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'DM-T', 'name_en' => 'Downstream type']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'DM-ORG', 'name_en' => 'Downstream', 'status' => 'active']);
    $this->employee = Employee::query()->create(['employee_number' => 'DM-E1', 'first_name' => 'Down', 'last_name' => 'Stream', 'full_name' => 'Down Stream', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->organization->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-01-01']);
    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();
    $this->actor = User::factory()->create();
});

it('reads the employee\'s organization through the Core contract', function (): void {
    $context = app(CafeteriaEmployeeContext::class);

    expect($context->organizationOf($this->employee->id))->toBe($this->organization->id)
        ->and($context->organizationOf(null))->toBeNull()
        ->and($context->organizationOf('not-a-uuid'))->toBeNull();
});

it('records the employee\'s organization on every cafeteria exclusion audit entry', function (): void {
    $exclusion = app(CreateEmployeeCafeteriaExclusionAction::class)->execute([
        'employee_id' => $this->employee->id, 'exclusion_type' => 'leave', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-10',
    ], $this->actor);
    app(EndEmployeeCafeteriaExclusionAction::class)->execute($exclusion->fresh(), $this->actor, '2026-10-11');
    app(ArchiveEmployeeCafeteriaExclusionAction::class)->execute($exclusion->fresh(), $this->actor, 'Entered by mistake');

    $rows = AuditLog::query()->where('event_type', 'like', 'employee_cafeteria_exclusion_%')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('organization_id')->unique()->values()->all())->toBe([$this->organization->id]);
});
