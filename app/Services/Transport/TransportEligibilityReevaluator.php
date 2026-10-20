<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\Transport\TransportExceptionType;
use App\Models\Employee;
use App\Models\TransportPass;
use Illuminate\Support\Facades\DB;

/**
 * Re-evaluates an employee's route assignments after a placement or status
 * change (transfer, separation). The employee and card identity never
 * change; what changes is whether the assigned route still serves them.
 * An assignment that no longer fits is suspended with a reason (never
 * deleted), the exception is recorded and the employee is told. The scan
 * itself always re-checks live status, so this is bookkeeping, not the gate.
 */
class TransportEligibilityReevaluator
{
    public const REASON_ROUTE = 'route_no_longer_serves_organization';

    public const REASON_EMPLOYEE = 'employee_no_longer_active';

    public const REASON_ASSIGNMENT = 'employee_assignment_missing';

    public function __construct(
        private readonly TransportRouteAccessService $routeAccess,
        private readonly TransportExceptionRecorder $exceptions,
        private readonly TransportEmployeeNotifier $notifier,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @return int how many assignments were suspended */
    public function reevaluate(Employee $employee): int
    {
        $employee->loadMissing('currentAssignment.organization');
        $suspended = 0;

        $passes = TransportPass::query()->with('route')->where('employee_id', $employee->id)->where('status', 'active')->get();
        foreach ($passes as $pass) {
            $reason = $this->reasonToSuspend($employee, $pass);
            if ($reason === null) {
                continue;
            }

            DB::transaction(function () use ($pass, $employee, $reason): void {
                $pass->forceFill(['status' => 'suspended', 'suspended_reason' => $reason, 'suspended_at' => now()])->save();

                $this->exceptions->record($pass->provider_id, TransportExceptionType::EligibilityWithdrawn, [
                    'reason' => $reason, 'pass_id' => $pass->id, 'route_id' => $pass->transport_route_id, 'organization_id' => $employee->currentAssignment?->organization_id,
                ], ['employee_id' => $employee->id], 'info');

                $this->audit->execute(AuditEventType::TransportRouteAssignmentChanged, null, $pass, $employee->currentAssignment?->organization_id,
                    oldValues: ['status' => 'active'], newValues: ['status' => 'suspended', 'suspended_reason' => $reason], reason: $reason);

                $this->notifier->assignmentSuspended($employee, $pass->route, $reason);
            });
            $suspended++;
        }

        return $suspended;
    }

    private function reasonToSuspend(Employee $employee, TransportPass $pass): ?string
    {
        if ($employee->status !== EmployeeStatus::Active) {
            return self::REASON_EMPLOYEE;
        }
        if ($employee->currentAssignment === null) {
            return self::REASON_ASSIGNMENT;
        }
        if ($pass->route !== null && ! $this->routeAccess->employeeCanUseRoute($employee, $pass->route)) {
            return self::REASON_ROUTE;
        }

        return null;
    }
}
