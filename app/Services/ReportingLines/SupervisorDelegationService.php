<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\TemporaryAuthorityStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\SupervisorDelegation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supervisor delegations (ADR 0025): a supervisor lends named actions to a
 * colleague of the same organization for a period. The delegator must have
 * something to delegate (direct reports on the start date), the delegate
 * must be another active employee, the actions must be from the catalog,
 * and the same delegate cannot hold two overlapping delegations from the
 * same person for the same action. What the delegate may then do is decided
 * live by {@see ReportingAuthority}, never stored.
 */
class SupervisorDelegationService
{
    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly ReportingLineResolver $resolver,
    ) {}

    /**
     * @param  array{delegate_employee_id: string, actions: list<string>, starts_on: string, ends_on?: ?string, reason?: ?string}  $data
     */
    public function delegate(User $actor, Employee $delegator, array $data): SupervisorDelegation
    {
        $startsOn = Carbon::parse($data['starts_on'])->toDateString();
        $endsOn = filled($data['ends_on'] ?? null) ? Carbon::parse((string) $data['ends_on'])->toDateString() : null;
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('reporting-lines.validation.ends_before_start')]);
        }
        $actions = array_values(array_unique(array_map('strval', $data['actions'] ?? [])));
        if ($actions === []) {
            throw ValidationException::withMessages(['actions' => __('reporting-lines.validation.actions_required')]);
        }
        foreach ($actions as $action) {
            if (! SupervisorActions::isKnown($action)) {
                throw ValidationException::withMessages(['actions' => __('reporting-lines.validation.action_unknown', ['action' => $action])]);
            }
        }
        if ((string) $data['delegate_employee_id'] === (string) $delegator->getKey()) {
            throw ValidationException::withMessages(['delegate_employee_id' => __('reporting-lines.validation.delegate_self')]);
        }

        return DB::transaction(function () use ($actor, $delegator, $data, $actions, $startsOn, $endsOn): SupervisorDelegation {
            // Serialised on the delegator's employee row: two overlapping delegations cannot both land.
            Employee::query()->whereKey($delegator->getKey())->lockForUpdate()->first(['id']);

            $delegate = Employee::query()->find($data['delegate_employee_id']);
            if ($delegate === null || $delegate->status !== EmployeeStatus::Active) {
                throw ValidationException::withMessages(['delegate_employee_id' => __('reporting-lines.validation.employee_inactive')]);
            }
            [$assignment] = $this->resolver->assignmentOn($delegator, $startsOn);
            $organizationId = $assignment?->organization_id === null ? null : (string) $assignment->organization_id;
            if ($organizationId === null) {
                throw ValidationException::withMessages(['delegator_employee_id' => __('reporting-lines.validation.delegator_no_assignment')]);
            }
            if (! $this->belongsToOrganization($delegate, $organizationId, $startsOn)) {
                throw ValidationException::withMessages(['delegate_employee_id' => __('reporting-lines.validation.employee_other_organization')]);
            }
            // Nothing to lend: the delegator supervises nobody on the start date.
            if ($this->resolver->directReportPositions($delegator, $startsOn) === []) {
                throw ValidationException::withMessages(['delegator_employee_id' => __('reporting-lines.validation.delegator_no_reports')]);
            }
            if ($this->overlaps((string) $delegator->getKey(), (string) $delegate->getKey(), $actions, $startsOn, $endsOn)) {
                throw ValidationException::withMessages(['starts_on' => __('reporting-lines.validation.delegation_overlap')]);
            }

            $delegation = SupervisorDelegation::query()->create([
                'organization_id' => $organizationId,
                'delegator_employee_id' => $delegator->getKey(),
                'delegate_employee_id' => $delegate->getKey(),
                'actions' => $actions,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'reason' => $data['reason'] ?? null,
                'status' => TemporaryAuthorityStatus::Active,
                'authorized_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->execute(AuditEventType::SupervisorDelegationCreated, $actor, $delegation, $organizationId, newValues: [
                'delegator_employee_id' => (string) $delegator->getKey(), 'delegate_employee_id' => (string) $delegate->getKey(),
                'actions' => $actions, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
            ], reason: $data['reason'] ?? null);

            return $delegation;
        });
    }

    public function revoke(User $actor, SupervisorDelegation $delegation, string $reason): SupervisorDelegation
    {
        return DB::transaction(function () use ($actor, $delegation, $reason): SupervisorDelegation {
            $locked = SupervisorDelegation::query()->lockForUpdate()->findOrFail($delegation->getKey());
            if ($locked->status !== TemporaryAuthorityStatus::Active) {
                throw ValidationException::withMessages(['status' => __('reporting-lines.validation.already_revoked')]);
            }
            $locked->forceFill([
                'status' => TemporaryAuthorityStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $actor->getKey(),
                'revocation_reason' => $reason,
            ])->save();

            $this->audit->execute(AuditEventType::SupervisorDelegationRevoked, $actor, $locked, (string) $locked->organization_id, ['status' => 'active'], ['status' => 'revoked'], $reason);

            return $locked;
        });
    }

    private function belongsToOrganization(Employee $employee, string $organizationId, string $day): bool
    {
        return EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('organization_id', $organizationId)
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->exists();
    }

    /** @param list<string> $actions */
    private function overlaps(string $delegatorId, string $delegateId, array $actions, string $startsOn, ?string $endsOn): bool
    {
        $existing = SupervisorDelegation::query()
            ->where('delegator_employee_id', $delegatorId)
            ->where('delegate_employee_id', $delegateId)
            ->where('status', TemporaryAuthorityStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn))
            ->when($endsOn !== null, fn ($q) => $q->whereDate('starts_on', '<=', $endsOn))
            ->get(['actions']);

        foreach ($existing as $row) {
            if (array_intersect($row->actions ?? [], $actions) !== []) {
                return true;
            }
        }

        return false;
    }
}
