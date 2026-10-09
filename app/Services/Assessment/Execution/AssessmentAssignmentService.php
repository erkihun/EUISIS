<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\Assessment\FormVersionStatus;
use App\Enums\AuditEventType;
use App\Jobs\Assessment\GenerateCycleAssessmentsJob;
use App\Models\AssessmentCycle;
use App\Models\AssessmentCycleEligibility;
use App\Models\AssessmentEvaluatorChange;
use App\Models\AssessmentEvaluatorScheme;
use App\Models\AssessmentFormVersion;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResponse;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Notifications\PerformanceNotification;
use App\Services\Assessment\Oversight\OversightAccess;
use App\Services\DailyActivity\EmployeeWorkContextResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Creates assessment assignments (records) and evaluator assignments
 * (responses) — docs/assessment-evaluator-workflow.md.
 *
 * Generation reads the cycle's finalized eligibility snapshot, never live
 * employee data, and uses the form version the snapshot resolved for each
 * employee. It is idempotent: one record per employee, type and period
 * (unique index) and one response per evaluator (unique index), so a retried
 * queue job never duplicates anything. Each employee is its own small
 * transaction; there is no giant transaction over a 180,000-employee cycle.
 */
class AssessmentAssignmentService
{
    public const CHUNK = 500;

    public function __construct(
        private readonly AssessmentEvaluatorResolver $resolver,
        private readonly EmployeeWorkContextResolver $context,
        private readonly OrganizationScopeService $scope,
        private readonly OversightAccess $access,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** Queue generation for a cycle (the web request only validates and dispatches). */
    public function queueGeneration(User $actor, AssessmentCycle $cycle): void
    {
        abort_unless($actor->can('assessment_assignments.generate') && $this->access->scopeFor($actor)->isCityWide(), 403);
        if (! $cycle->isEligibilityFinalized()) {
            throw ValidationException::withMessages(['cycle' => 'Finalize the cycle eligibility before generating assignments.']);
        }
        if ($cycle->status !== 'active') {
            throw ValidationException::withMessages(['cycle' => 'Only an active cycle generates assignments.']);
        }
        $cycle->update(['assignment_generation_status' => 'queued']);
        $this->audit->execute(AuditEventType::AssessmentAssignmentsGenerated, $actor, $cycle, newValues: ['action' => 'queued'], request: request());
        GenerateCycleAssessmentsJob::dispatch($cycle->id, $actor->id);
    }

    /**
     * One chunk of eligibility rows. Returns the evaluator assignments created,
     * so the caller can batch notifications.
     *
     * @param  array<int, string>  $eligibilityIds
     * @return Collection<int, AssessmentResponse>
     */
    public function generateChunk(AssessmentCycle $cycle, array $eligibilityIds, User $actor): Collection
    {
        $created = collect();
        $rows = AssessmentCycleEligibility::query()->where('assessment_cycle_id', $cycle->id)->whereIn('id', $eligibilityIds)->where('eligibility_status', 'eligible')
            ->where('form_resolution', 'matched')->whereNotNull('expected_form_version_id')->get();
        $versions = AssessmentFormVersion::query()->with('evaluatorSchemes')->whereIn('id', $rows->pluck('expected_form_version_id')->unique())->get()->keyBy('id');

        foreach ($rows as $row) {
            $version = $versions->get($row->expected_form_version_id);
            if ($version === null || $version->status === FormVersionStatus::Draft) {
                continue;
            }
            $created = $created->merge($this->assignForEligibility($cycle, $row, $version, $actor));
        }

        return $created;
    }

    /**
     * The record for one eligible employee plus every evaluator the system can
     * resolve. Safe to call again: existing rows are reused, missing
     * resolvable evaluators are added, nothing is duplicated.
     *
     * @return Collection<int, AssessmentResponse> newly created evaluator assignments
     */
    public function assignForEligibility(AssessmentCycle $cycle, AssessmentCycleEligibility $row, AssessmentFormVersion $version, User $actor): Collection
    {
        return DB::transaction(function () use ($cycle, $row, $version, $actor): Collection {
            $employee = Employee::query()->lockForUpdate()->findOrFail($row->employee_id);
            $assignment = $row->employee_assignment_id ? EmployeeAssignment::query()->with(['organization', 'organizationUnit', 'position'])->find($row->employee_assignment_id) : null;
            $record = AssessmentRecord::query()->firstOrCreate(
                ['employee_id' => $employee->id, 'assessment_type_id' => $cycle->assessment_type_id, 'period_start' => $cycle->period_start->toDateString(), 'period_end' => $cycle->period_end->toDateString()],
                [
                    'assessment_cycle_id' => $cycle->id, 'organization_id' => $row->organization_id, 'organization_unit_id' => $row->organization_unit_id, 'form_version_id' => $version->id,
                    'employee_snapshot' => $this->snapshot($employee, $assignment), 'status' => 'pending_assignment', 'created_by' => $actor->id,
                ],
            );
            if ($record->wasRecentlyCreated) {
                $this->audit->execute(AuditEventType::AssessmentAssignmentCreated, $actor, $record, $record->organization_id, newValues: ['employee_id' => $employee->id, 'form_version_id' => $version->id], request: request());
            }
            $created = $this->fillResolvable($record, $version, $employee, $assignment, $actor);
            $this->refreshAssignmentStatus($record);

            return $created;
        });
    }

    /**
     * Evaluators for schemes that need a person to choose them (peers etc.),
     * or a named replacement. Exactly the configured number per scheme.
     *
     * @param  array<int, int>  $userIds
     */
    public function assignEvaluators(User $actor, AssessmentRecord $record, string $evaluatorType, array $userIds, ?string $reason = null, bool $authorize = true): void
    {
        if ($authorize) {
            $this->authorizeManage($actor, $record);
        }
        DB::transaction(function () use ($actor, $record, $evaluatorType, $userIds, $reason): void {
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($record->id);
            if ($record->isFinalized() || in_array($record->status, ['unassessed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['record' => 'This assessment can no longer change evaluators.']);
            }
            $scheme = $record->version->evaluatorSchemes->first(fn ($s) => $s->evaluator_type->value === $evaluatorType);
            if ($scheme === null) {
                throw ValidationException::withMessages(['evaluator_type' => 'This form has no such evaluator type.']);
            }
            $active = $record->responses()->where('evaluator_type', $evaluatorType)->whereIn('status', AssessmentResponse::ACTIVE)->count();
            $open = $scheme->required_count - $active;
            $userIds = array_values(array_unique(array_map('intval', $userIds)));
            if ($open <= 0 || count($userIds) !== $open) {
                throw ValidationException::withMessages(['evaluator_ids' => "Select exactly {$open} evaluator(s) for this slot."]);
            }
            foreach ($userIds as $userId) {
                $user = $this->validateEvaluator($record, $scheme, $userId);
                $response = $this->createResponse($record, $scheme, $user);
                $this->logChange($record, null, $response, $evaluatorType, 'initial', $reason, $actor);
            }
            $this->refreshAssignmentStatus($record);
        });
    }

    /**
     * Replace an evaluator before they submit (transferred, unavailable, left,
     * conflict, incorrect). The old assignment is cancelled, never deleted.
     */
    public function reassign(User $actor, AssessmentResponse $response, int $newUserId, string $reasonCode, string $reason): AssessmentResponse
    {
        $record = $response->record;
        $this->authorizeManage($actor, $record);
        if (! in_array($reasonCode, ['transferred', 'unavailable', 'left_employment', 'conflict', 'incorrect'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Choose a reason and explain the change.']);
        }

        return DB::transaction(function () use ($actor, $response, $newUserId, $reasonCode, $reason): AssessmentResponse {
            $response = AssessmentResponse::query()->lockForUpdate()->findOrFail($response->id);
            $record = AssessmentRecord::query()->lockForUpdate()->findOrFail($response->assessment_record_id);
            if (! in_array($response->status, ['not_started', 'in_progress', 'returned', 'conflict_declared'], true) || $record->isFinalized()) {
                throw ValidationException::withMessages(['response' => 'A submitted, cancelled or finalized evaluation cannot be reassigned.']);
            }
            $scheme = $response->scheme ?? $record->version->evaluatorSchemes->first(fn ($s) => $s->evaluator_type->value === $response->evaluator_type);
            $user = $this->validateEvaluator($record, $scheme, $newUserId);
            $response->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $new = $this->createResponse($record, $scheme, $user);
            $this->logChange($record, $response, $new, $response->evaluator_type, $reasonCode, $reason, $actor);
            $this->audit->execute(AuditEventType::AssessmentEvaluatorChanged, $actor, $record, $record->organization_id,
                ['evaluator_id' => $response->evaluator_id], ['evaluator_id' => $user->id, 'reason_code' => $reasonCode], $reason, request());
            $this->notify(collect([$new]));

            return $new;
        });
    }

    /** The evaluator declares a conflict of interest; an authorized reviewer then reassigns or rejects. */
    public function declareConflict(User $actor, AssessmentResponse $response, string $reason): void
    {
        abort_unless($response->evaluator_id === $actor->id && $actor->can('assessments.complete_assigned'), 403);
        if (! $response->isEditable() || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Explain the conflict of interest.']);
        }
        $response->update(['status' => 'conflict_declared', 'conflict_declared_at' => now(), 'conflict_reason' => $reason]);
        $this->audit->execute(AuditEventType::AssessmentConflictDeclared, $actor, $response->record, $response->record->organization_id, newValues: ['response_id' => $response->id], reason: $reason, request: request());
    }

    public function rejectConflict(User $actor, AssessmentResponse $response, string $reason): void
    {
        $this->authorizeManage($actor, $response->record);
        if ($response->status !== 'conflict_declared' || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the reason for keeping this evaluator.']);
        }
        $response->update(['status' => $response->items()->exists() ? 'in_progress' : 'not_started']);
        $this->audit->execute(AuditEventType::AssessmentConflictRejected, $actor, $response->record, $response->record->organization_id, newValues: ['response_id' => $response->id], reason: $reason, request: request());
    }

    /** Notify evaluators of new assignments, one batch call (database channel only; no responses in the text). */
    public function notify(Collection $responses): void
    {
        $byUser = $responses->groupBy('evaluator_id');
        $users = User::query()->whereIn('id', $byUser->keys())->where('status', 'active')->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, new PerformanceNotification('assessment_assigned', route('assessment-workspace.index', [], false), ['database']));
        }
    }

    /** pending_assignment until every configured evaluator slot has a person. */
    public function refreshAssignmentStatus(AssessmentRecord $record): void
    {
        if (! in_array($record->status, ['pending_assignment', 'assigned'], true)) {
            return;
        }
        $missing = $this->openSlots($record)->sum('open');
        $record->update(['status' => $missing > 0 ? 'pending_assignment' : 'assigned']);
    }

    /** @return Collection<int, array{type: string, required: int, active: int, open: int, selection: string}> */
    public function openSlots(AssessmentRecord $record): Collection
    {
        $counts = $record->responses()->whereIn('status', AssessmentResponse::ACTIVE)->selectRaw('evaluator_type, COUNT(*) as n')->groupBy('evaluator_type')->pluck('n', 'evaluator_type');

        return $record->version->evaluatorSchemes->map(fn (AssessmentEvaluatorScheme $s): array => [
            'type' => $s->evaluator_type->value, 'required' => $s->required_count, 'active' => (int) ($counts[$s->evaluator_type->value] ?? 0),
            'open' => max(0, $s->required_count - (int) ($counts[$s->evaluator_type->value] ?? 0)), 'selection' => $s->selection_method->value,
        ])->values();
    }

    public function canManage(User $actor, AssessmentRecord $record): bool
    {
        return $actor->can('assessment_assignments.reassign') && $this->scope->canAccessOrganization($actor, $record->organization_id);
    }

    /**
     * Add the evaluators the system resolves itself (self, direct manager) when
     * their slot is empty. The reference date is the cycle's, else the period end.
     *
     * @return Collection<int, AssessmentResponse>
     */
    public function fillResolvable(AssessmentRecord $record, AssessmentFormVersion $version, Employee $employee, ?EmployeeAssignment $assignment, User $actor): Collection
    {
        $cycle = $record->cycle;
        $reference = Carbon::parse($cycle?->reference_date ?? $record->period_end);
        $created = collect();
        foreach ($version->evaluatorSchemes as $scheme) {
            $type = $scheme->evaluator_type->value;
            if (! in_array($type, AssessmentEvaluatorResolver::RESOLVABLE, true)) {
                continue;
            }
            if ($record->responses()->where('evaluator_type', $type)->whereIn('status', AssessmentResponse::ACTIVE)->exists()) {
                continue;
            }
            $user = $type === 'self' ? $this->resolver->selfUser($employee) : $this->resolver->directManager($employee, $assignment, $reference);
            if ($user === null || AssessmentResponse::query()->where('assessment_record_id', $record->id)->where('evaluator_id', $user->id)->exists()) {
                continue;
            }
            $response = $this->createResponse($record, $scheme, $user, $cycle);
            $this->logChange($record, null, $response, $type, 'initial', 'Resolved automatically', $actor);
            $created->push($response);
        }

        return $created;
    }

    private function createResponse(AssessmentRecord $record, AssessmentEvaluatorScheme $scheme, User $user, ?AssessmentCycle $cycle = null): AssessmentResponse
    {
        $cycle ??= $record->cycle;

        return $record->responses()->create([
            'evaluator_id' => $user->id, 'evaluator_type' => $scheme->evaluator_type->value, 'evaluator_scheme_id' => $scheme->id,
            'status' => 'not_started', 'is_anonymous' => (bool) $scheme->is_anonymous, 'due_at' => $cycle?->evaluation_due_date,
        ]);
    }

    private function validateEvaluator(AssessmentRecord $record, AssessmentEvaluatorScheme $scheme, int $userId): User
    {
        $user = User::query()->find($userId);
        $type = $scheme->evaluator_type->value;
        $isEmployee = $user?->employee_id === $record->employee_id;
        $fail = fn (string $message) => throw ValidationException::withMessages(['evaluator_ids' => $message]);
        if ($user === null || ! $user->isActive()) {
            $fail('Choose an active user account.');
        }
        if ($type === 'self' ? ! $isEmployee : $isEmployee) {
            $fail($type === 'self' ? 'A self-assessment is completed by the employee.' : 'An employee cannot evaluate themself.');
        }
        if (AssessmentResponse::query()->where('assessment_record_id', $record->id)->where('evaluator_id', $user->id)->exists()) {
            $fail('This person already has an evaluator assignment on this assessment.');
        }
        if ($type === 'peer') {
            $assignment = $user->employee ? $this->context->assignmentOn($user->employee, Carbon::parse($record->period_end)) : null;
            if ($assignment?->organization_id !== $record->organization_id) {
                $fail('A peer must work in the same institution on the assessment period end.');
            }
        }
        if (! $user->can('assessments.complete_assigned')) {
            $fail('This user does not have the permission to complete assessments.');
        }

        return $user;
    }

    private function logChange(AssessmentRecord $record, ?AssessmentResponse $from, ?AssessmentResponse $to, string $type, string $code, ?string $reason, User $actor): void
    {
        AssessmentEvaluatorChange::query()->create([
            'assessment_record_id' => $record->id, 'from_response_id' => $from?->id, 'to_response_id' => $to?->id,
            'from_evaluator_id' => $from?->evaluator_id, 'to_evaluator_id' => $to?->evaluator_id, 'evaluator_type' => $type,
            'reason_code' => $code, 'reason' => $reason, 'changed_by' => $actor->id, 'created_at' => now(),
        ]);
    }

    private function authorizeManage(User $actor, AssessmentRecord $record): void
    {
        abort_unless($this->canManage($actor, $record), 403);
    }

    /** @return array<string, mixed> the employee's placement frozen for this assessment */
    public function snapshot(Employee $employee, ?EmployeeAssignment $assignment): array
    {
        return [
            'name' => $employee->full_name, 'name_en' => $employee->name_en, 'number' => $employee->employee_number, 'gender' => $employee->gender,
            'organization_id' => $assignment?->organization_id, 'organization' => $assignment?->organization?->name_en, 'organization_am' => $assignment?->organization?->name_am,
            'unit_id' => $assignment?->organization_unit_id, 'unit' => $assignment?->organizationUnit?->name_en, 'unit_am' => $assignment?->organizationUnit?->name_am,
            'position_id' => $assignment?->position_id, 'position' => $assignment?->position?->title_en, 'position_am' => $assignment?->position?->title_am,
            'grade' => $assignment?->position?->grade_level,
        ];
    }
}
