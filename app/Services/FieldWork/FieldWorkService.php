<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\EmployeeStatus;
use App\Enums\FieldWorkDestinationType;
use App\Enums\FieldWorkHistoryAction;
use App\Enums\FieldWorkLocationEventType;
use App\Enums\FieldWorkLocationValidation;
use App\Enums\FieldWorkParticipantRole;
use App\Enums\FieldWorkScheduleType;
use App\Enums\FieldWorkStatus;
use App\Enums\FieldWorkSupervisorResolution;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\FieldWorkLocationEvent;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkType;
use App\Models\User;
use App\Services\DailyActivity\EmployeeWorkContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every Field Work state change goes through here.
 *
 * Each transition runs in a transaction on a row-locked request and checks
 * the CURRENT status, so of two racing actions (double submit, approve vs.
 * reject, double check-in) exactly one commits and the other sees the new
 * state and fails cleanly. Identity is enforced here as well as in the
 * policy, because Gate::before lets Super Admin pass every policy:
 *
 *   requester   only the request's own employee edits, submits, cancels, completes
 *   supervisor  only the live-resolved immediate supervisor decides
 *   participant only the participant themself checks in / out
 *
 * Field work never creates, changes or closes an employee assignment; the
 * placement is only READ and snapshotted.
 */
class FieldWorkService
{
    public function __construct(
        private readonly EmployeeWorkContextResolver $context,
        private readonly FieldWorkSupervisorResolver $supervisors,
        private readonly FieldWorkConflictDetector $conflicts,
        private readonly FieldWorkGeofence $geofence,
        private readonly FieldWorkSettings $settings,
        private readonly FieldWorkNotifier $notifier,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated FieldWorkRequestData
     */
    public function create(User $actor, array $data, bool $submit = false): FieldWorkRequest
    {
        $employee = $this->requireEmployee($actor);
        $assignment = $this->requireAssignment($employee);
        $members = $this->resolveMembers($actor, $employee, $assignment, $data['participant_employee_ids'] ?? []);

        $request = DB::transaction(function () use ($actor, $employee, $assignment, $data, $members): FieldWorkRequest {
            $request = new FieldWorkRequest;
            $this->fillContent($request, $data);
            $request->forceFill([
                'reference_number' => $this->newReference(),
                'requester_employee_id' => $employee->id,
                'requester_user_id' => $actor->getKey(),
                'status' => FieldWorkStatus::Draft,
            ]);
            $this->snapshotPlacement($request, $assignment);
            $request->is_team = $members !== [];
            $request->save();

            $this->syncParticipants($request, $employee, $assignment, $members);
            $this->history($request, FieldWorkHistoryAction::Created, $actor, null, FieldWorkStatus::Draft, null);
            $this->audit->execute(AuditEventType::FieldWorkCreated, $actor, $request, $request->organization_id, null, [
                'reference_number' => $request->reference_number,
                'is_team' => $request->is_team,
            ]);

            return $request;
        });

        return $submit ? $this->submit($actor, $request) : $request;
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, FieldWorkRequest $request, array $data, bool $submit = false): FieldWorkRequest
    {
        $employee = $this->requireEmployee($actor);
        $this->assertRequester($employee, $request);
        // A correction re-reads the placement: the request reflects where the
        // requester works when it is (re)sent for approval.
        $assignment = $this->requireAssignment($employee);
        $members = $this->resolveMembers($actor, $employee, $assignment, $data['participant_employee_ids'] ?? []);

        $request = DB::transaction(function () use ($actor, $employee, $assignment, $request, $data, $members): FieldWorkRequest {
            $locked = $this->lock($request);
            if (! $locked->status->isRequesterEditable()) {
                throw $this->locked($locked);
            }

            $this->fillContent($locked, $data);
            $this->snapshotPlacement($locked, $assignment);
            $locked->is_team = $members !== [];
            $locked->save();

            $this->syncParticipants($locked, $employee, $assignment, $members);
            $this->history($locked, FieldWorkHistoryAction::Updated, $actor, $locked->status, $locked->status, null);
            $this->audit->execute(AuditEventType::FieldWorkUpdated, $actor, $locked, $locked->organization_id);

            return $locked;
        });

        return $submit ? $this->submit($actor, $request) : $request;
    }

    public function submit(User $actor, FieldWorkRequest $request): FieldWorkRequest
    {
        $employee = $this->requireEmployee($actor);
        $this->assertRequester($employee, $request);

        $request = DB::transaction(function () use ($actor, $request): FieldWorkRequest {
            $locked = $this->lock($request);
            $from = $locked->status;
            $this->assertCanMove($locked, FieldWorkStatus::PendingSupervisorApproval);
            $this->assertNoConflicts($locked);

            $supervisor = $this->supervisors->resolveFor($locked);
            $locked->forceFill([
                'status' => FieldWorkStatus::PendingSupervisorApproval,
                'supervisor_user_id' => $supervisor?->getKey(),
                'supervisor_resolution' => $supervisor !== null ? FieldWorkSupervisorResolution::Resolved : FieldWorkSupervisorResolution::NotResolved,
                'submitted_at' => now(),
                'submission_count' => $locked->submission_count + 1,
                'decided_at' => null,
                'decided_by' => null,
                'decision_reason' => null,
            ])->save();

            $this->history($locked, FieldWorkHistoryAction::Submitted, $actor, $from, $locked->status, null);
            $this->audit->execute(AuditEventType::FieldWorkSubmitted, $actor, $locked, $locked->organization_id, ['status' => $from->value], [
                'status' => $locked->status->value,
                'supervisor_resolution' => $locked->supervisor_resolution->value,
            ]);

            return $locked;
        });

        $this->notifier->approvalRequired($request);

        return $request;
    }

    public function approve(User $actor, FieldWorkRequest $request, ?string $comment): FieldWorkRequest
    {
        return $this->decide($actor, $request, FieldWorkStatus::Approved, FieldWorkHistoryAction::Approved, AuditEventType::FieldWorkApproved, $this->nullableText($comment), 'approved');
    }

    public function returnForCorrection(User $actor, FieldWorkRequest $request, string $reason): FieldWorkRequest
    {
        return $this->decide($actor, $request, FieldWorkStatus::ReturnedForCorrection, FieldWorkHistoryAction::Returned, AuditEventType::FieldWorkReturned, $this->requiredText($reason, 'reason'), 'returned');
    }

    public function reject(User $actor, FieldWorkRequest $request, string $reason): FieldWorkRequest
    {
        return $this->decide($actor, $request, FieldWorkStatus::Rejected, FieldWorkHistoryAction::Rejected, AuditEventType::FieldWorkRejected, $this->requiredText($reason, 'reason'), 'rejected');
    }

    /** Requester only; never once anyone has checked in. */
    public function cancel(User $actor, FieldWorkRequest $request, ?string $reason): FieldWorkRequest
    {
        $employee = $this->requireEmployee($actor);
        $this->assertRequester($employee, $request);

        return DB::transaction(function () use ($actor, $request, $reason): FieldWorkRequest {
            $locked = $this->lock($request);
            $from = $locked->status;
            $this->assertCanMove($locked, FieldWorkStatus::Cancelled);
            if ($locked->participants()->whereNotNull('checked_in_at')->exists()) {
                throw ValidationException::withMessages(['status' => __('field-work.errors.already_started')]);
            }

            $locked->forceFill([
                'status' => FieldWorkStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancel_reason' => $this->nullableText($reason),
            ])->save();

            $this->history($locked, FieldWorkHistoryAction::Cancelled, $actor, $from, $locked->status, $locked->cancel_reason);
            $this->audit->execute(AuditEventType::FieldWorkCancelled, $actor, $locked, $locked->organization_id, ['status' => $from->value], ['status' => $locked->status->value], $locked->cancel_reason);

            return $locked;
        });
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy: ?float, captured_at: Carbon}  $gps
     * @return array{0: FieldWorkLocationEvent, 1: bool} the event, and whether this call created it
     */
    public function checkIn(User $actor, FieldWorkRequest $request, array $gps): array
    {
        $employee = $this->requireEmployee($actor);
        $this->assertFreshCapture($gps['captured_at']);

        return DB::transaction(function () use ($actor, $employee, $request, $gps): array {
            $locked = $this->lock($request);
            $participant = $this->lockParticipant($locked, $employee);

            // Idempotent retry: a second tap returns the recorded check-in.
            if ($participant->checked_in_at !== null) {
                return [$this->existingEvent($participant, FieldWorkLocationEventType::FieldCheckIn), false];
            }
            if (! in_array($locked->status, [FieldWorkStatus::Approved, FieldWorkStatus::InField], true)) {
                throw ValidationException::withMessages(['status' => __('field-work.errors.not_approved')]);
            }

            $now = now();
            if ($now->lt($locked->starts_at->copy()->subMinutes($this->settings->checkInEarlyMinutes()))) {
                throw ValidationException::withMessages(['status' => __('field-work.errors.too_early')]);
            }
            if ($now->gte($locked->expected_return_at)) {
                throw ValidationException::withMessages(['status' => __('field-work.errors.window_closed')]);
            }

            $event = $this->recordEvent($actor, $locked, $participant, FieldWorkLocationEventType::FieldCheckIn, $gps);
            $participant->forceFill(['checked_in_at' => $now])->save();

            $from = $locked->status;
            if ($locked->status === FieldWorkStatus::Approved) {
                $locked->forceFill(['status' => FieldWorkStatus::InField, 'actual_start_at' => $now])->save();
            }

            $this->history($locked, FieldWorkHistoryAction::CheckedIn, $actor, $from, $locked->status, null);
            $this->auditLocation(AuditEventType::FieldWorkCheckedIn, $actor, $locked, $event);

            return [$event, true];
        });
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy: ?float, captured_at: Carbon}  $gps
     * @return array{0: FieldWorkLocationEvent, 1: bool}
     */
    public function checkOut(User $actor, FieldWorkRequest $request, array $gps): array
    {
        $employee = $this->requireEmployee($actor);
        $this->assertFreshCapture($gps['captured_at']);

        return DB::transaction(function () use ($actor, $employee, $request, $gps): array {
            $locked = $this->lock($request);
            $participant = $this->lockParticipant($locked, $employee);

            if ($participant->checked_out_at !== null) {
                return [$this->existingEvent($participant, FieldWorkLocationEventType::FieldCheckOut), false];
            }
            if ($participant->checked_in_at === null) {
                throw ValidationException::withMessages(['status' => __('field-work.errors.not_checked_in')]);
            }
            if ($locked->status !== FieldWorkStatus::InField) {
                throw ValidationException::withMessages(['status' => __('field-work.errors.not_in_field')]);
            }

            $event = $this->recordEvent($actor, $locked, $participant, FieldWorkLocationEventType::FieldCheckOut, $gps);
            $participant->forceFill(['checked_out_at' => now()])->save();

            $this->history($locked, FieldWorkHistoryAction::CheckedOut, $actor, $locked->status, $locked->status, null);
            $this->auditLocation(AuditEventType::FieldWorkCheckedOut, $actor, $locked, $event);

            return [$event, true];
        });
    }

    /**
     * Requester closes the work. The actual return is what the requester
     * declares (defaulting client-side to their GPS check-out); nothing is
     * invented for participants who never checked out.
     *
     * @param  array{actual_return_at: Carbon, completion_note: string, outcome: ?string, follow_up_required: bool, follow_up_note: ?string}  $data
     */
    public function complete(User $actor, FieldWorkRequest $request, array $data): FieldWorkRequest
    {
        $employee = $this->requireEmployee($actor);
        $this->assertRequester($employee, $request);

        return DB::transaction(function () use ($actor, $request, $data): FieldWorkRequest {
            $locked = $this->lock($request);
            $from = $locked->status;
            $this->assertCanMove($locked, FieldWorkStatus::Completed);

            $actualReturn = $data['actual_return_at'];
            if ($actualReturn->gt(now()) || ($locked->actual_start_at !== null && $actualReturn->lt($locked->actual_start_at))) {
                throw ValidationException::withMessages(['actual_return_date' => __('field-work.errors.actual_return_range')]);
            }

            $locked->forceFill([
                'status' => FieldWorkStatus::Completed,
                'actual_return_at' => $actualReturn,
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
                'completion_note' => $this->requiredText($data['completion_note'], 'completion_note'),
                'outcome' => $this->nullableText($data['outcome'] ?? null),
                'follow_up_required' => (bool) $data['follow_up_required'],
                'follow_up_note' => $data['follow_up_required'] ? $this->nullableText($data['follow_up_note'] ?? null) : null,
            ])->save();

            $this->history($locked, FieldWorkHistoryAction::Completed, $actor, $from, $locked->status, null);
            $this->audit->execute(AuditEventType::FieldWorkCompleted, $actor, $locked, $locked->organization_id, ['status' => $from->value], [
                'status' => $locked->status->value,
                'actual_return_at' => $actualReturn->toIso8601String(),
            ]);

            return $locked;
        });
    }

    // ── Workflow internals ───────────────────────────────────────────────────

    private function decide(User $actor, FieldWorkRequest $request, FieldWorkStatus $to, FieldWorkHistoryAction $action, AuditEventType $event, ?string $comment, string $notice): FieldWorkRequest
    {
        $request = DB::transaction(function () use ($actor, $request, $to, $action, $event, $comment): FieldWorkRequest {
            $locked = $this->lock($request);
            $from = $locked->status;
            $this->assertCanMove($locked, $to);

            if (! $this->supervisors->isSupervisorOf($actor, $locked)) {
                throw new AuthorizationException(__('field-work.errors.not_supervisor'));
            }
            if ($to === FieldWorkStatus::Approved) {
                // Re-checked under the lock: something approved since submission still blocks.
                $this->assertNoConflicts($locked);
            }

            $locked->forceFill([
                'status' => $to,
                'decided_at' => now(),
                'decided_by' => $actor->getKey(),
                'decision_reason' => $comment,
                'supervisor_user_id' => $actor->getKey(),
                'supervisor_resolution' => FieldWorkSupervisorResolution::Resolved,
            ])->save();

            $this->history($locked, $action, $actor, $from, $to, $comment);
            $this->audit->execute($event, $actor, $locked, $locked->organization_id, ['status' => $from->value], ['status' => $to->value], $comment);

            return $locked;
        });

        $this->notifier->decided($request, $notice, $comment);

        return $request;
    }

    private function lock(FieldWorkRequest $request): FieldWorkRequest
    {
        return FieldWorkRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockParticipant(FieldWorkRequest $request, Employee $employee): FieldWorkParticipant
    {
        $participant = FieldWorkParticipant::query()
            ->where('field_work_request_id', $request->id)
            ->where('employee_id', $employee->id)
            ->lockForUpdate()
            ->first();

        if ($participant === null) {
            throw new AuthorizationException(__('field-work.errors.not_participant'));
        }

        return $participant;
    }

    private function assertCanMove(FieldWorkRequest $request, FieldWorkStatus $to): void
    {
        if (! $request->status->canTransitionTo($to)) {
            throw $this->locked($request);
        }
    }

    private function locked(FieldWorkRequest $request): ValidationException
    {
        return ValidationException::withMessages(['status' => __('field-work.errors.invalid_transition', [
            'status' => __("field-work.status.{$request->status->value}"),
        ])]);
    }

    private function assertNoConflicts(FieldWorkRequest $request): void
    {
        $employeeIds = $request->participants()->pluck('employee_id')->all();
        $conflicts = $this->conflicts->conflicts($employeeIds, $request->starts_at, $request->expected_return_at, $request->id);

        if ($conflicts !== []) {
            $names = Employee::query()->whereIn('id', array_unique(array_column($conflicts, 'employee_id')))->pluck('full_name', 'id');
            $lines = array_map(static fn (array $conflict): string => __("field-work.errors.conflict_{$conflict['kind']}", [
                'employee' => (string) ($names[$conflict['employee_id']] ?? ''),
                'reference' => (string) ($conflict['reference'] ?? ''),
            ]), $conflicts);

            throw ValidationException::withMessages(['conflicts' => array_values(array_unique($lines))]);
        }
    }

    private function assertRequester(Employee $employee, FieldWorkRequest $request): void
    {
        if ($employee->id !== $request->requester_employee_id) {
            throw new AuthorizationException(__('field-work.errors.not_requester'));
        }
    }

    private function requireEmployee(User $actor): Employee
    {
        $employee = $actor->employee;
        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages(['employee' => __('field-work.errors.no_employee')]);
        }

        return $employee;
    }

    private function requireAssignment(Employee $employee): EmployeeAssignment
    {
        $today = $this->settings->today();
        if (! $this->context->isEmployedOn($employee, $today)) {
            throw ValidationException::withMessages(['employee' => __('field-work.errors.not_active')]);
        }

        $assignment = $this->context->assignmentOn($employee, $today);
        if ($assignment === null) {
            throw ValidationException::withMessages(['employee' => __('field-work.errors.no_assignment')]);
        }

        return $assignment;
    }

    /**
     * Team members: active employees placed TODAY in the requester's own
     * organization, chosen only with field_work.create_team. Ids that fail
     * any rule are rejected as a whole, never silently dropped.
     *
     * @param  array<int, mixed>  $ids
     * @return array<string, EmployeeAssignment> employee id => current assignment
     */
    private function resolveMembers(User $actor, Employee $requester, EmployeeAssignment $requesterAssignment, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn (string $id): bool => $id !== '' && Str::isUuid($id))));
        $ids = array_values(array_diff($ids, [$requester->id]));
        if ($ids === []) {
            return [];
        }
        if (! $actor->can('field_work.create_team')) {
            throw ValidationException::withMessages(['participant_employee_ids' => __('field-work.errors.team_not_allowed')]);
        }
        if (count($ids) > $this->settings->maxParticipants()) {
            throw ValidationException::withMessages(['participant_employee_ids' => __('field-work.errors.too_many_participants', ['max' => $this->settings->maxParticipants()])]);
        }

        $today = $this->settings->today();
        $employees = Employee::query()->whereIn('id', $ids)->get()->keyBy('id');
        $assignments = $this->context->assignmentsFor($ids, $today, $today);
        $histories = $this->context->statusHistoriesFor($ids, $today, $today);
        $resolved = [];

        foreach ($ids as $id) {
            $employee = $employees->get($id);
            $assignment = $employee ? $this->context->pickAssignment($assignments->get($id, collect()), $today->toDateString()) : null;
            $active = $employee && $this->context->statusOn($employee, $histories->get($id, collect()), $today->toDateString()) === EmployeeStatus::Active;

            if (! $active || $assignment === null || $assignment->organization_id !== $requesterAssignment->organization_id) {
                throw ValidationException::withMessages(['participant_employee_ids' => __('field-work.errors.invalid_participant')]);
            }
            $resolved[$id] = $assignment;
        }

        return $resolved;
    }

    /** @param array<string, EmployeeAssignment> $members */
    private function syncParticipants(FieldWorkRequest $request, Employee $requester, EmployeeAssignment $requesterAssignment, array $members): void
    {
        $wanted = [$requester->id => [$requesterAssignment, FieldWorkParticipantRole::Lead], ...array_map(
            static fn (EmployeeAssignment $assignment): array => [$assignment, FieldWorkParticipantRole::Member],
            $members,
        )];

        // Only reachable while editable (nobody can have checked in yet).
        $request->participants()->whereNotIn('employee_id', array_keys($wanted))->delete();
        $existing = $request->participants()->get()->keyBy('employee_id');

        foreach ($wanted as $employeeId => [$assignment, $role]) {
            $participant = $existing->get($employeeId) ?? new FieldWorkParticipant;
            $participant->forceFill([
                'field_work_request_id' => $request->id,
                'employee_id' => $employeeId,
                'role' => $role,
                'employee_assignment_id' => $assignment->id,
                'organization_id' => $assignment->organization_id,
                'organization_unit_id' => $assignment->organization_unit_id,
                'position_id' => $assignment->position_id,
            ])->save();
        }

        $request->unsetRelation('participants');
    }

    /** @param array<string, mixed> $data */
    private function fillContent(FieldWorkRequest $request, array $data): void
    {
        $type = FieldWorkType::query()->whereKey($data['field_work_type_id'])->first();
        // A type deactivated after the request was drafted stays valid for it.
        if ($type === null || (! $type->is_active && $request->field_work_type_id !== $type->id)) {
            throw ValidationException::withMessages(['field_work_type_id' => __('field-work.errors.inactive_type')]);
        }

        $destination = FieldWorkDestinationType::from($data['destination_type']);
        $registered = $destination === FieldWorkDestinationType::RegisteredOrganization;

        $request->fill([
            'field_work_type_id' => $type->id,
            'purpose' => trim((string) $data['purpose']),
            'activity_description' => $this->nullableText($data['activity_description'] ?? null),
            'destination_type' => $destination,
            'destination_organization_id' => $registered ? $data['destination_organization_id'] : null,
            'destination_organization_unit_id' => $registered ? ($data['destination_organization_unit_id'] ?? null) : null,
            'external_organization_name' => $destination === FieldWorkDestinationType::ExternalOrganization ? trim((string) $data['external_organization_name']) : null,
            'site_name' => $registered ? null : $this->nullableText($data['site_name'] ?? null),
            'destination_address' => $this->nullableText($data['destination_address'] ?? null),
            'contact_person' => $this->nullableText($data['contact_person'] ?? null),
            'contact_phone' => $this->nullableText($data['contact_phone'] ?? null),
            'expected_latitude' => $data['expected_latitude'] ?? null,
            'expected_longitude' => $data['expected_longitude'] ?? null,
            'geofence_radius_m' => $data['geofence_radius_m'] ?? null,
            'starts_at' => $data['starts_at'],
            'expected_return_at' => $data['expected_return_at'],
        ]);
        $request->schedule_type = $this->scheduleType($data['starts_at'], $data['expected_return_at']);
    }

    private function snapshotPlacement(FieldWorkRequest $request, EmployeeAssignment $assignment): void
    {
        $assignment->loadMissing(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'position:id,title_en,title_am']);

        $request->forceFill([
            'employee_assignment_id' => $assignment->id,
            'organization_id' => $assignment->organization_id,
            'organization_unit_id' => $assignment->organization_unit_id,
            'position_id' => $assignment->position_id,
            'context_snapshot' => [
                'organization' => ['name_en' => $assignment->organization?->name_en, 'name_am' => $assignment->organization?->name_am],
                'organization_unit' => $assignment->organizationUnit ? ['name_en' => $assignment->organizationUnit->name_en, 'name_am' => $assignment->organizationUnit->name_am] : null,
                'position' => $assignment->position ? ['name_en' => $assignment->position->title_en, 'name_am' => $assignment->position->title_am] : null,
                'captured_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** Same local day and under 8 hours: partial; same day otherwise: full; else multi-day. */
    private function scheduleType(Carbon $start, Carbon $end): FieldWorkScheduleType
    {
        $timezone = $this->settings->timezone();
        if ($start->copy()->setTimezone($timezone)->toDateString() !== $end->copy()->setTimezone($timezone)->toDateString()) {
            return FieldWorkScheduleType::MultiDay;
        }

        return $start->diffInMinutes($end) >= 8 * 60 ? FieldWorkScheduleType::FullDay : FieldWorkScheduleType::PartialDay;
    }

    /** @param array{latitude: float, longitude: float, accuracy: ?float, captured_at: Carbon} $gps */
    private function recordEvent(User $actor, FieldWorkRequest $request, FieldWorkParticipant $participant, FieldWorkLocationEventType $type, array $gps): FieldWorkLocationEvent
    {
        $check = $this->geofence->evaluate($request, $gps['latitude'], $gps['longitude'], $gps['accuracy']);

        if ($check['status'] === FieldWorkLocationValidation::OutsideExpectedArea && $this->settings->blockOutsideExpectedArea()) {
            throw ValidationException::withMessages(['location' => __('field-work.errors.outside_area')]);
        }

        $event = new FieldWorkLocationEvent;
        $event->forceFill([
            'field_work_request_id' => $request->id,
            'field_work_participant_id' => $participant->id,
            'employee_id' => $participant->employee_id,
            'event_type' => $type,
            'latitude' => $gps['latitude'],
            'longitude' => $gps['longitude'],
            'accuracy_m' => $gps['accuracy'],
            'captured_at' => $gps['captured_at'],
            'received_at' => now(),
            'distance_m' => $check['distance_m'],
            'validation_status' => $check['status'],
            'recorded_by' => $actor->getKey(),
        ])->save();

        return $event;
    }

    private function existingEvent(FieldWorkParticipant $participant, FieldWorkLocationEventType $type): FieldWorkLocationEvent
    {
        return FieldWorkLocationEvent::query()
            ->where('field_work_participant_id', $participant->id)
            ->where('event_type', $type->value)
            ->firstOrFail();
    }

    private function assertFreshCapture(Carbon $capturedAt): void
    {
        $now = now();
        if ($capturedAt->gt($now->copy()->addMinutes($this->settings->maxFutureSkewMinutes()))
            || $capturedAt->lt($now->copy()->subMinutes($this->settings->maxCaptureAgeMinutes()))) {
            throw ValidationException::withMessages(['captured_at' => __('field-work.errors.stale_capture')]);
        }
    }

    /** Coordinates never go into the general audit log; the event row is the record. */
    private function auditLocation(AuditEventType $type, User $actor, FieldWorkRequest $request, FieldWorkLocationEvent $event): void
    {
        $this->audit->execute($type, $actor, $request, $request->organization_id, null, [
            'location_event_id' => $event->id,
            'validation_status' => $event->validation_status->value,
            'status' => $request->status->value,
        ]);
    }

    private function history(FieldWorkRequest $request, FieldWorkHistoryAction $action, User $actor, ?FieldWorkStatus $from, ?FieldWorkStatus $to, ?string $comment): void
    {
        $request->histories()->create([
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_user_id' => $actor->getKey(),
            'comment' => $comment,
        ]);
    }

    private function newReference(): string
    {
        $prefix = 'FW-'.$this->settings->today()->format('Ymd').'-';
        do {
            $reference = $prefix.Str::upper(Str::random(6));
        } while (FieldWorkRequest::query()->where('reference_number', $reference)->exists());

        return $reference;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : $value;
    }

    private function requiredText(mixed $value, string $field): string
    {
        $value = $this->nullableText($value);
        if ($value === null) {
            throw ValidationException::withMessages([$field => __('field-work.errors.reason_required')]);
        }

        return $value;
    }
}
