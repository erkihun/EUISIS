<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class RequestEmployeeTransferAction
{
    use TransferWorkflowGuard;

    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(
        Employee $employee,
        string $targetOrganizationId,
        User $actor,
        ?string $reason = null,
        ?string $effectiveDate = null,
        ?string $toPositionId = null,
    ): EmployeeTransfer {
        return DB::transaction(function () use ($employee, $targetOrganizationId, $actor, $reason, $effectiveDate, $toPositionId): EmployeeTransfer {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $employee->load(['currentAssignment', 'transfers']);
            $this->ensureEmployeeCanTransfer($employee);
            $this->ensureTransferTarget($employee, $targetOrganizationId, $toPositionId, $effectiveDate);

            $transfer = EmployeeTransfer::query()->create([
                'employee_id' => $employee->id,
                'from_organization_id' => $employee->currentAssignment->organization_id,
                'to_organization_id' => $targetOrganizationId,
                'from_position_id' => $employee->currentAssignment->position_id,
                'to_position_id' => $toPositionId,
                'current_assignment_id' => $employee->currentAssignment->id,
                'requested_by' => $actor->id,
                'transfer_reason' => $reason,
                'effective_date' => $effectiveDate ?? now()->toDateString(),
                'status' => TransferStatus::Draft,
            ]);

            $this->writeAuditLogAction->execute(
                AuditEventType::TransferRequested,
                $actor,
                $transfer,
                $targetOrganizationId,
                newValues: $transfer->toArray(),
                reason: $reason,
            );

            return $transfer;
        });
    }
}
