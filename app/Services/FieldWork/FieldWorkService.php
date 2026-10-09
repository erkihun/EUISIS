<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\FieldWorkDestinationType;
use App\Enums\FieldWorkStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkSession;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\DailyActivity\EmployeeWorkContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Transactional field-work lifecycle. It deliberately does not write attendance or biometric events. */
final class FieldWorkService
{
    public function __construct(private readonly EmployeeWorkContextResolver $contexts, private readonly FieldWorkSupervisorResolver $supervisors, private readonly WriteAuditLogAction $audit, private readonly FieldWorkGpsPolicyService $gpsPolicy) {}

    public function createDraft(User $actor, Employee $employee, array $data): FieldWorkRequest
    {
        return DB::transaction(function () use ($actor, $employee, $data): FieldWorkRequest {
            $startsAt = Carbon::parse($data['starts_at']);
            $assignment = $this->contexts->assignmentOn($employee, $startsAt);
            if ($assignment === null) {
                throw ValidationException::withMessages(['starts_at' => __('field-work.no_active_assignment')]);
            }
            $this->validateDestination($data);
            $this->assertNoConflict($employee->id, $startsAt, Carbon::parse($data['expected_return_at']));
            $request = new FieldWorkRequest;
            $request->forceFill([
                ...$this->requestValues($data), 'reference_number' => $this->nextReference($startsAt), 'requester_employee_id' => $employee->id, 'requester_assignment_id' => $assignment->id,
                'organization_id' => $assignment->organization_id, 'organization_unit_id' => $assignment->organization_unit_id, 'position_id' => $assignment->position_id,
                ...$this->snapshot($assignment), 'status' => FieldWorkStatus::Draft, 'created_by' => $actor->id,
            ]);
            $request->save();
            $this->addParticipant($request, $employee, $assignment, true);
            $this->audit->execute(AuditEventType::FieldWorkCreated, $actor, $request, $assignment->organization_id, newValues: ['reference_number' => $request->reference_number, 'status' => $request->status->value]);

            return $request;
        });
    }

    public function updateDraft(User $actor, FieldWorkRequest $request, array $data): FieldWorkRequest
    {
        if (! $request->status->isEditable()) {
            throw new AuthorizationException('This field-work request is not editable.');
        }

        return DB::transaction(function () use ($actor, $request, $data): FieldWorkRequest {
            $this->validateDestination($data);
            $this->assertNoConflict($request->requester_employee_id, Carbon::parse($data['starts_at']), Carbon::parse($data['expected_return_at']), $request->id);
            $request->forceFill([...$this->requestValues($data), 'updated_by' => $actor->id])->save();

            return $request->refresh();
        });
    }

    public function submit(User $actor, FieldWorkRequest $request): FieldWorkRequest
    {
        return DB::transaction(function () use ($actor, $request): FieldWorkRequest {
            $request = FieldWorkRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! $request->status->isEditable()) {
                throw new AuthorizationException('Invalid field-work transition.');
            }
            $supervisor = $this->supervisors->resolve($request->requester, $request->assignment);
            if ($supervisor === null) {
                throw ValidationException::withMessages(['supervisor' => __('field-work.supervisor_not_resolved')]);
            }
            $supervisor->loadMissing('employee');
            $request->forceFill([
                'status' => FieldWorkStatus::PendingSupervisorApproval,
                'submitted_at' => now(),
                'supervisor_user_id' => $supervisor->id,
                'supervisor_employee_id' => $supervisor->employee?->id,
                'supervisor_name_snapshot' => $supervisor->employee?->full_name ?? $supervisor->name,
                'supervisor_employee_number_snapshot' => $supervisor->employee?->employee_number,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->execute(AuditEventType::FieldWorkSubmitted, $actor, $request, $request->organization_id, newValues: ['status' => $request->status->value]);

            return $request;
        });
    }

    public function approve(User $actor, FieldWorkRequest $request, ?string $note = null): FieldWorkRequest
    {
        return $this->review($actor, $request, FieldWorkStatus::Approved, $note, null);
    }

    public function returnForCorrection(User $actor, FieldWorkRequest $request, string $reason): FieldWorkRequest
    {
        return $this->review($actor, $request, FieldWorkStatus::ReturnedForCorrection, null, $reason);
    }

    public function reject(User $actor, FieldWorkRequest $request, string $reason): FieldWorkRequest
    {
        return $this->review($actor, $request, FieldWorkStatus::Rejected, null, $reason);
    }

    public function complete(User $actor, FieldWorkRequest $request, Carbon $actualReturn, ?string $note): FieldWorkRequest
    {
        return DB::transaction(function () use ($actor, $request, $actualReturn, $note): FieldWorkRequest {
            $request = FieldWorkRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== FieldWorkStatus::Approved || $actualReturn->lessThan($request->starts_at) || $actualReturn->isFuture()) {
                throw ValidationException::withMessages(['actual_return_at' => __('field-work.invalid_completion')]);
            }
            $own = $actor->employee?->id === $request->requester_employee_id;
            if (! $own && ! $this->supervisors->canApprove($actor, $request->requester, $request->assignment)) {
                throw new AuthorizationException;
            }
            if ($request->fieldWorkType->requires_completion_note && blank($note)) {
                throw ValidationException::withMessages(['completion_note' => __('validation.required', ['attribute' => 'completion note'])]);
            }
            $this->assertRequiredLocationEvents($request);
            $request->forceFill(['status' => FieldWorkStatus::Completed, 'actual_return_at' => $actualReturn, 'completed_at' => now(), 'completed_by_employee_id' => $actor->employee?->id, 'completion_note' => $note, 'updated_by' => $actor->id])->save();
            $this->audit->execute(AuditEventType::FieldWorkCompleted, $actor, $request, $request->organization_id, newValues: ['status' => $request->status->value]);

            return $request;
        });
    }

    private function assertRequiredLocationEvents(FieldWorkRequest $request): void
    {
        if (! $this->gpsPolicy->requiresCheckIn() && ! $this->gpsPolicy->requiresCheckOut()) {
            return;
        }

        $participant = FieldWorkParticipant::query()
            ->where('field_work_request_id', $request->id)
            ->where('is_primary_requester', true)
            ->first();
        if ($participant === null) {
            throw ValidationException::withMessages(['location' => 'The primary field-work participant is missing.']);
        }

        $session = FieldWorkSession::query()
            ->where('field_work_request_id', $request->id)
            ->where('field_work_participant_id', $participant->id)
            ->first();
        if ($this->gpsPolicy->requiresCheckIn() && $session?->checked_in_at === null) {
            throw ValidationException::withMessages(['location' => 'A GPS check-in is required before completion.']);
        }
        if ($this->gpsPolicy->requiresCheckOut() && $session?->checked_out_at === null) {
            throw ValidationException::withMessages(['location' => 'A GPS check-out is required before completion.']);
        }
    }

    private function review(User $actor, FieldWorkRequest $request, FieldWorkStatus $next, ?string $note, ?string $reason): FieldWorkRequest
    {
        return DB::transaction(function () use ($actor, $request, $next, $note, $reason): FieldWorkRequest {
            $request = FieldWorkRequest::query()->with(['requester', 'assignment'])->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== FieldWorkStatus::PendingSupervisorApproval || ! $this->supervisors->canApprove($actor, $request->requester, $request->assignment)) {
                throw new AuthorizationException;
            }
            $values = ['status' => $next, 'updated_by' => $actor->id];
            if ($next === FieldWorkStatus::Approved) {
                $values += ['approved_by_employee_id' => $actor->employee?->id, 'approved_at' => now(), 'approval_note' => $note];
            }
            if ($next === FieldWorkStatus::ReturnedForCorrection) {
                $values += ['returned_by_employee_id' => $actor->employee?->id, 'returned_at' => now(), 'return_reason' => $reason];
            }
            if ($next === FieldWorkStatus::Rejected) {
                $values += ['rejected_by_employee_id' => $actor->employee?->id, 'rejected_at' => now(), 'rejection_reason' => $reason];
            }
            $request->forceFill($values)->save();
            $event = $next === FieldWorkStatus::Approved ? AuditEventType::FieldWorkApproved : ($next === FieldWorkStatus::Rejected ? AuditEventType::FieldWorkRejected : AuditEventType::FieldWorkReturned);
            $this->audit->execute($event, $actor, $request, $request->organization_id, newValues: ['status' => $next->value], reason: $reason);

            return $request;
        });
    }

    private function requestValues(array $data): array
    {
        $values = collect($data)->only(['field_work_type_id', 'destination_type', 'destination_organization_id', 'destination_organization_unit_id', 'external_organization_name', 'external_contact_person', 'external_contact_phone', 'destination_location', 'destination_address', 'destination_latitude', 'destination_longitude', 'destination_radius_meters', 'purpose', 'activity_description', 'starts_at', 'expected_return_at', 'actual_departure_at', 'is_full_day', 'is_multi_day'])->all();
        $type = FieldWorkDestinationType::from($data['destination_type']);

        if ($type !== FieldWorkDestinationType::RegisteredOrganization) {
            $values['destination_organization_id'] = null;
            $values['destination_organization_unit_id'] = null;
        }
        if ($type !== FieldWorkDestinationType::ExternalOrganization) {
            $values['external_organization_name'] = null;
            $values['external_contact_person'] = null;
            $values['external_contact_phone'] = null;
        }

        return $values;
    }

    private function snapshot(EmployeeAssignment $assignment): array
    {
        $assignment->loadMissing(['organization:id,name_en', 'organizationUnit:id,name_en', 'position:id,title_en']);

        return ['organization_name_snapshot' => $assignment->organization?->name_en, 'organization_unit_name_snapshot' => $assignment->organizationUnit?->name_en, 'position_name_snapshot' => $assignment->position?->title_en];
    }

    private function addParticipant(FieldWorkRequest $request, Employee $employee, EmployeeAssignment $assignment, bool $primary): void
    {
        $participant = new FieldWorkParticipant;
        $participant->forceFill(['field_work_request_id' => $request->id, 'employee_id' => $employee->id, 'employee_assignment_id' => $assignment->id, 'organization_id' => $assignment->organization_id, 'organization_unit_id' => $assignment->organization_unit_id, 'position_id' => $assignment->position_id, 'participant_role' => $primary ? 'requester' : 'participant', 'is_primary_requester' => $primary]);
        $participant->save();
    }

    private function validateDestination(array $data): void
    {
        $type = FieldWorkDestinationType::from($data['destination_type']);
        if ($type === FieldWorkDestinationType::RegisteredOrganization) {
            $organization = Organization::query()->find($data['destination_organization_id'] ?? null);
            if ($organization === null) {
                throw ValidationException::withMessages(['destination_organization_id' => __('validation.exists', ['attribute' => 'destination organization'])]);
            }
            $unitId = $data['destination_organization_unit_id'] ?? null;
            if ($unitId !== null && ! OrganizationUnit::query()->whereKey($unitId)->where('organization_id', $organization->id)->exists()) {
                throw ValidationException::withMessages(['destination_organization_unit_id' => __('field-work.invalid_destination_unit')]);
            }
        }
        if ($type === FieldWorkDestinationType::ExternalOrganization && blank($data['external_organization_name'] ?? null)) {
            throw ValidationException::withMessages(['external_organization_name' => __('field-work.external_organization_required')]);
        }
        if (in_array($type, [FieldWorkDestinationType::FieldSite, FieldWorkDestinationType::OtherLocation], true) && blank($data['destination_location'] ?? null)) {
            throw ValidationException::withMessages(['destination_location' => __('validation.required', ['attribute' => 'destination location'])]);
        }
    }

    private function assertNoConflict(string $employeeId, Carbon $starts, Carbon $ends, ?string $exceptId = null): void
    {
        $conflict = FieldWorkRequest::query()->where('requester_employee_id', $employeeId)->whereIn('status', [FieldWorkStatus::PendingSupervisorApproval->value, FieldWorkStatus::Approved->value])->where('starts_at', '<', $ends)->where('expected_return_at', '>', $starts)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['starts_at' => __('field-work.overlap')]);
        }
    }

    private function nextReference(Carbon $when): string
    {
        $year = $when->year;
        // Establish the annual counter before locking it. ON CONFLICT removes
        // the first-request race without relying on a raw request id.
        DB::statement('insert into field_work_number_sequences (year, next_number, created_at, updated_at) values (?, 1, ?, ?) on conflict (year) do nothing', [$year, now(), now()]);
        $row = DB::table('field_work_number_sequences')->where('year', $year)->lockForUpdate()->first();
        if ($row === null) {
            throw new \RuntimeException('Unable to lock the field-work reference sequence.');
        }
        $number = $row->next_number;
        DB::table('field_work_number_sequences')->where('year', $year)->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('FW-%d-%06d', $year, $number);
    }
}
