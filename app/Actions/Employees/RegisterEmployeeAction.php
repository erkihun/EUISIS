<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\CodeRuleEntityType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmploymentStatusHistory;
use App\Models\User;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Illuminate\Support\Facades\DB;

readonly class RegisterEmployeeAction
{
    public function __construct(
        private DetectDuplicateEmployeeAction $detectDuplicateEmployeeAction,
        private WriteAuditLogAction $writeAuditLogAction,
        private GenerateCodeAction $generateCodeAction,
        private EmployeeFeedbackTokenService $feedbackTokenService,
    ) {}

    public function execute(array $employeeAttributes, array $assignmentAttributes, User $actor): Employee
    {
        return DB::transaction(function () use ($employeeAttributes, $assignmentAttributes, $actor): Employee {
            $employeeAttributes['employee_number'] = $this->generateCodeAction->execute(
                CodeRuleEntityType::Employee,
                [
                    'organization_id' => $assignmentAttributes['organization_id'] ?? null,
                ],
                $actor,
                $employeeAttributes['employee_number'] ?? null,
                'employee_number',
            );

            $employee = Employee::query()->create($employeeAttributes + [
                'status' => $employeeAttributes['status'] ?? EmployeeStatus::Active,
            ]);

            $assignment = EmployeeAssignment::query()->create($assignmentAttributes + [
                'employee_id' => $employee->id,
                'assignment_status' => AssignmentStatus::Active,
                'is_current' => true,
            ]);

            $employee->update(['current_assignment_id' => $assignment->id]);

            EmploymentStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'status' => $employee->status,
                'effective_from' => $assignment->effective_from,
            ]);

            $this->detectDuplicateEmployeeAction->execute($employee);

            /*
             * Provision the public feedback QR up front so every active
             * employee has a scannable code from day one, rather than an
             * administrator having to remember to press "Generate" later.
             *
             * Inside the same transaction: if registration rolls back, the
             * token must not survive pointing at an employee that never existed.
             */
            $this->feedbackTokenService->ensureActiveTokenFor($employee, $actor);

            $this->writeAuditLogAction->execute(
                AuditEventType::EmployeeCreated,
                $actor,
                $employee,
                $assignment->organization_id,
                newValues: $employee->fresh(['currentAssignment'])?->toArray(),
            );

            return $employee->fresh(['currentAssignment']);
        });
    }
}
