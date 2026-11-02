<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\TemporaryAuthorityStatus;
use App\Models\ActingAppointment;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acting appointments (ADR 0025): one employee fills one position for a
 * period. Serialised on the position row so two appointments for the same
 * period cannot both land; the substantive holder's assignment is never
 * touched; revocation keeps the row and says who ended it and why.
 */
class ActingAppointmentService
{
    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly ReportingGraphRepository $graphs,
    ) {}

    /**
     * @param  array{position_id: string, employee_id: string, starts_on: string, ends_on?: ?string, reason?: ?string}  $data
     */
    public function appoint(User $actor, array $data): ActingAppointment
    {
        $startsOn = Carbon::parse($data['starts_on'])->toDateString();
        $endsOn = filled($data['ends_on'] ?? null) ? Carbon::parse((string) $data['ends_on'])->toDateString() : null;
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('reporting-lines.validation.ends_before_start')]);
        }

        return DB::transaction(function () use ($actor, $data, $startsOn, $endsOn): ActingAppointment {
            $position = Position::query()->lockForUpdate()->find($data['position_id']);
            if ($position === null || ! $position->is_active || $position->trashed()) {
                throw ValidationException::withMessages(['position_id' => __('reporting-lines.validation.position_inactive')]);
            }
            $employee = Employee::query()->find($data['employee_id']);
            if ($employee === null || $employee->status !== EmployeeStatus::Active) {
                throw ValidationException::withMessages(['employee_id' => __('reporting-lines.validation.employee_inactive')]);
            }
            $organizationId = (string) $position->organization_id;
            if (! $this->belongsToOrganization($employee, $organizationId, $startsOn)) {
                throw ValidationException::withMessages(['employee_id' => __('reporting-lines.validation.employee_other_organization')]);
            }
            if ($this->holdsSubstantively($employee, (string) $position->getKey(), $startsOn)) {
                throw ValidationException::withMessages(['employee_id' => __('reporting-lines.validation.already_holds_position')]);
            }
            if ($this->overlaps((string) $position->getKey(), $startsOn, $endsOn)) {
                throw ValidationException::withMessages(['starts_on' => __('reporting-lines.validation.acting_overlap')]);
            }

            $appointment = ActingAppointment::query()->create([
                'organization_id' => $organizationId,
                'position_id' => $position->getKey(),
                'employee_id' => $employee->getKey(),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'reason' => $data['reason'] ?? null,
                'status' => TemporaryAuthorityStatus::Active,
                'authorized_by' => $actor->getKey(),
                'created_by' => $actor->getKey(),
            ]);
            $this->graphs->forget();

            $this->audit->execute(AuditEventType::ActingAppointmentCreated, $actor, $appointment, $organizationId, newValues: [
                'position_id' => (string) $position->getKey(), 'employee_id' => (string) $employee->getKey(),
                'starts_on' => $startsOn, 'ends_on' => $endsOn,
            ], reason: $data['reason'] ?? null);

            return $appointment;
        });
    }

    public function revoke(User $actor, ActingAppointment $appointment, string $reason): ActingAppointment
    {
        return DB::transaction(function () use ($actor, $appointment, $reason): ActingAppointment {
            $locked = ActingAppointment::query()->lockForUpdate()->findOrFail($appointment->getKey());
            if ($locked->status !== TemporaryAuthorityStatus::Active) {
                throw ValidationException::withMessages(['status' => __('reporting-lines.validation.already_revoked')]);
            }
            $locked->forceFill([
                'status' => TemporaryAuthorityStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $actor->getKey(),
                'revocation_reason' => $reason,
            ])->save();
            $this->graphs->forget();

            $this->audit->execute(AuditEventType::ActingAppointmentRevoked, $actor, $locked, (string) $locked->organization_id, ['status' => 'active'], ['status' => 'revoked'], $reason);

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

    private function holdsSubstantively(Employee $employee, string $positionId, string $day): bool
    {
        return EmployeeAssignment::query()
            ->where('employee_id', $employee->getKey())
            ->where('position_id', $positionId)
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->exists();
    }

    private function overlaps(string $positionId, string $startsOn, ?string $endsOn): bool
    {
        return ActingAppointment::query()
            ->where('position_id', $positionId)
            ->where('status', TemporaryAuthorityStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn))
            ->when($endsOn !== null, fn ($q) => $q->whereDate('starts_on', '<=', $endsOn))
            ->exists();
    }
}
