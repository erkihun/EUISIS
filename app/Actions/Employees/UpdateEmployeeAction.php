<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmploymentStatusTransition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Core write for an employee master-data change (HR edit, approved
 * self-service correction). One transaction: the employee is re-read under a
 * row lock so a concurrent change is not overwritten from a stale model, the
 * employment status transition ends or resumes the placement (ADR 0002), and
 * the EmployeeUpdated audit row records the old and new values.
 *
 * The employee UUID and number are never regenerated here; file handling
 * (photos) stays with the caller.
 */
readonly class UpdateEmployeeAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private EmploymentStatusTransition $statusTransition,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(Employee $employee, array $attributes, User $actor, ?Request $request = null, ?string $reason = null): Employee
    {
        return DB::transaction(function () use ($employee, $attributes, $actor, $request, $reason): Employee {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());
            $oldValues = $locked->toArray();
            $previousStatus = $locked->status;

            try {
                $locked->update($attributes);
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent registration or edit took the same national ID first (CON-10).
                if (str_contains($e->getMessage(), 'national_id_hash')) {
                    throw ValidationException::withMessages(['national_id' => __('validation.unique', ['attribute' => 'national id'])]);
                }

                throw $e;
            }

            $this->statusTransition->apply($locked, $previousStatus, $locked->status, $actor, $request);

            $fresh = $locked->fresh();
            $this->writeAuditLogAction->execute(
                AuditEventType::EmployeeUpdated,
                $actor,
                $fresh,
                $fresh->currentAssignment?->organization_id,
                oldValues: $oldValues,
                newValues: $fresh->toArray(),
                reason: $reason,
                request: $request,
            );

            return $fresh;
        });
    }
}
