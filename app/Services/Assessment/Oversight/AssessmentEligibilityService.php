<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Models\AssessmentCycle;
use App\Models\AssessmentCycleEligibility;
use App\Models\AssessmentExclusionRequest;
use App\Models\AssessmentUnassessedReason;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\User;
use App\Services\Assessment\AssessmentTargetResolver;
use App\Services\DailyActivity\EmployeeWorkContextResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The cycle denominator (docs/assessment-coverage.md#eligibility).
 *
 * An employee is in a cycle's population when, on the cycle's reference
 * date, their assignment is in a participating institution. They are
 * ELIGIBLE unless a configured rule excludes them (employment status,
 * minimum service, or — under the target_rules population rule — no
 * applicable form). The result is snapshotted; after finalization the
 * denominator moves only through an approved exclusion, and every change
 * is audited.
 */
class AssessmentEligibilityService
{
    public function __construct(
        private readonly AssessmentTargetResolver $targets,
        private readonly EmployeeWorkContextResolver $context,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * Eligibility decisions for a batch of employees, without writing.
     *
     * @param  array<int, string>  $employeeIds
     * @param  array<int, string>  $organizationIds  participating institutions
     * @return array<string, array<string, mixed>> employee id => decision (only employees placed in a participating institution)
     */
    public function resolveEligibility(AssessmentCycle $cycle, array $employeeIds, array $organizationIds, ?Collection $versions = null, ?array $reasons = null): array
    {
        $date = Carbon::parse($cycle->reference_date);
        $day = $date->toDateString();
        $versions ??= $this->targets->candidates($cycle->assessment_type_id, $date);
        $reasons ??= $this->systemReasons();
        $assignments = $this->context->assignmentsFor($employeeIds, $date, $date);
        $histories = $this->context->statusHistoriesFor($employeeIds, $date, $date);
        $employees = Employee::query()->whereIn('id', $employeeIds)->get(['id', 'status'])->keyBy('id');
        $serviceStart = $cycle->min_service_days === null ? collect() : EmployeeAssignment::query()->whereIn('employee_id', $employeeIds)
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->groupBy('employee_id')->selectRaw('employee_id, MIN(effective_from) as first_day')->pluck('first_day', 'employee_id');

        $picked = [];
        foreach ($employeeIds as $id) {
            $assignment = $this->context->pickAssignment($assignments->get($id, collect()), $day);
            if ($assignment !== null && in_array($assignment->organization_id, $organizationIds, true) && $employees->has($id)) {
                $picked[$id] = $assignment;
            }
        }
        $positions = Position::query()->whereIn('id', collect($picked)->pluck('position_id')->filter()->unique()->values())->get(['id', 'occupation_id', 'grade_level', 'job_family'])->keyBy('id');
        $statuses = $cycle->eligibleStatuses();

        $result = [];
        foreach ($picked as $id => $assignment) {
            $status = $this->context->statusOn($employees[$id], $histories->get($id, collect()), $day)->value;
            $decision = $this->targets->decideForAssignment($versions, $assignment, $positions->get($assignment->position_id), $day);
            $reason = null;
            if (! in_array($status, $statuses, true)) {
                $reason = 'employment_status';
            } elseif ($cycle->min_service_days !== null && ($first = $serviceStart->get($id)) !== null
                && Carbon::parse($first)->addDays($cycle->min_service_days)->toDateString() > $day) {
                $reason = 'new_employee';
            } elseif ($cycle->population_rule === 'target_rules' && $decision['status'] === AssessmentTargetResolver::NO_APPLICABLE_FORM) {
                $reason = 'no_applicable_form';
            }
            $result[$id] = [
                'employee_id' => $id, 'employee_assignment_id' => $assignment->id, 'organization_id' => $assignment->organization_id,
                'organization_unit_id' => $assignment->organization_unit_id, 'position_id' => $assignment->position_id,
                'employment_status' => $status,
                'eligibility_status' => $reason === null ? 'eligible' : 'excluded',
                'reason_code' => $reason, 'reason_id' => $reason === null ? null : ($reasons[$reason] ?? null),
                'reason_source' => $reason === null ? null : 'system_detected',
                'form_resolution' => $decision['status'], 'expected_form_version_id' => $decision['version']?->id,
            ];
        }

        return $result;
    }

    /**
     * (Re)build the snapshot while eligibility is open. Chunked so 180,000
     * employees never sit in memory at once.
     *
     * @return array{eligible: int, excluded: int}
     */
    public function snapshotCycleEligibility(User $actor, AssessmentCycle $cycle, int $chunk = 1000): array
    {
        if ($cycle->isEligibilityFinalized()) {
            throw ValidationException::withMessages(['eligibility' => 'Eligibility is finalized. The denominator now changes only through approved exclusions.']);
        }
        $organizationIds = $cycle->organizations()->where('status', 'included')->pluck('organization_id')->map(fn ($id): string => (string) $id)->all();
        if ($organizationIds === []) {
            throw ValidationException::withMessages(['eligibility' => 'Add at least one participating institution first.']);
        }
        $missing = array_diff(['employment_status', 'new_employee', 'no_applicable_form'], array_keys($this->systemReasons()));
        if ($missing !== []) {
            throw ValidationException::withMessages(['eligibility' => 'System unassessed reasons are missing: '.implode(', ', $missing).'.']);
        }

        $date = Carbon::parse($cycle->reference_date)->toDateString();
        $versions = $this->targets->candidates($cycle->assessment_type_id, Carbon::parse($date));
        $reasons = $this->systemReasons();
        $counts = ['eligible' => 0, 'excluded' => 0];
        $now = now();

        DB::transaction(function () use ($cycle, $organizationIds, $date, $versions, $reasons, $chunk, $now, &$counts): void {
            AssessmentCycleEligibility::query()->where('assessment_cycle_id', $cycle->id)->delete();
            EmployeeAssignment::query()
                ->whereIn('organization_id', $organizationIds)
                ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->select('employee_id')->distinct()->orderBy('employee_id')
                ->chunk($chunk, function ($rows) use ($cycle, $organizationIds, $versions, $reasons, $now, &$counts): void {
                    $decisions = $this->resolveEligibility($cycle, $rows->pluck('employee_id')->map(fn ($id): string => (string) $id)->all(), $organizationIds, $versions, $reasons);
                    $insert = [];
                    foreach ($decisions as $decision) {
                        $counts[$decision['eligibility_status']]++;
                        $insert[] = [
                            'id' => (string) Str::uuid7(), 'assessment_cycle_id' => $cycle->id,
                            ...array_intersect_key($decision, array_flip(['employee_id', 'employee_assignment_id', 'organization_id', 'organization_unit_id', 'position_id', 'eligibility_status', 'reason_id', 'reason_source', 'form_resolution', 'expected_form_version_id'])),
                            'snapshot_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                    foreach (array_chunk($insert, 500) as $part) {
                        DB::table('assessment_cycle_employee_eligibility')->insert($part);
                    }
                });
            $cycle->update(['eligibility_snapshot_at' => $now]);
        });

        $this->audit->execute(AuditEventType::AssessmentEligibilitySnapshotCreated, $actor, $cycle, newValues: $counts + ['reference_date' => $date], request: request());
        AssessmentCoverageService::bump($cycle->id);

        return $counts;
    }

    public function finalize(User $actor, AssessmentCycle $cycle): void
    {
        if ($cycle->isEligibilityFinalized()) {
            return;
        }
        if ($cycle->eligibility_snapshot_at === null) {
            throw ValidationException::withMessages(['eligibility' => 'Create the eligibility snapshot first.']);
        }
        $cycle->update(['eligibility_status' => 'finalized', 'eligibility_finalized_at' => now(), 'eligibility_finalized_by' => $actor->id]);
        $this->audit->execute(AuditEventType::AssessmentEligibilityFinalized, $actor, $cycle, newValues: ['eligible' => $cycle->eligibility()->where('eligibility_status', 'eligible')->count()], request: request());
        AssessmentCoverageService::bump($cycle->id);
    }

    /** Institution asks to exclude an eligible employee; it changes nothing until approved. */
    public function requestExclusion(User $actor, AssessmentCycleEligibility $row, AssessmentUnassessedReason $reason, string $note): AssessmentExclusionRequest
    {
        $cycle = $row->cycle;
        if (! $cycle->isEligibilityFinalized()) {
            throw ValidationException::withMessages(['eligibility' => 'Exclusions are requested against the finalized eligibility snapshot.']);
        }
        if ($row->eligibility_status !== 'eligible' || $row->reason_source === 'approved_exception') {
            throw ValidationException::withMessages(['eligibility' => 'This employee already has an approved exception or is not eligible.']);
        }
        if (! $reason->is_active || $reason->source !== 'approved_exception') {
            throw ValidationException::withMessages(['reason_id' => 'Choose an active exception reason.']);
        }
        if (AssessmentExclusionRequest::query()->where('eligibility_id', $row->id)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['eligibility' => 'A request for this employee is already pending.']);
        }

        $request = AssessmentExclusionRequest::query()->create([
            'assessment_cycle_id' => $cycle->id, 'eligibility_id' => $row->id, 'employee_id' => $row->employee_id,
            'organization_id' => $row->organization_id, 'reason_id' => $reason->id, 'note' => $note,
            'status' => 'pending', 'requested_by' => $actor->id, 'requested_at' => now(),
        ]);
        $this->audit->execute(AuditEventType::AssessmentExclusionRequested, $actor, $request, $row->organization_id, newValues: ['employee_id' => $row->employee_id, 'reason' => $reason->code], request: request());
        AssessmentCoverageService::bump($cycle->id);

        return $request;
    }

    /** Approve or reject; never by the requester (two people per denominator change). */
    public function decide(User $actor, AssessmentExclusionRequest $request, bool $approve, ?string $note): void
    {
        DB::transaction(function () use ($actor, $request, $approve, $note): void {
            $request = AssessmentExclusionRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'This request has already been decided.']);
            }
            abort_if($request->requested_by === $actor->id, 403, 'The requester cannot decide their own exclusion request.');
            if (! $approve && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'Give the reason for rejecting.']);
            }
            $request->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            if ($approve) {
                $this->applyApprovedExclusion($request);
            }
            $this->audit->execute($approve ? AuditEventType::AssessmentExclusionApproved : AuditEventType::AssessmentExclusionRejected, $actor, $request, $request->organization_id,
                newValues: ['employee_id' => $request->employee_id, 'note' => $note], request: request());
        });
        AssessmentCoverageService::bump($request->assessment_cycle_id);
    }

    public function withdraw(User $actor, AssessmentExclusionRequest $request): void
    {
        abort_unless($request->requested_by === $actor->id, 403);
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['request' => 'Only a pending request can be withdrawn.']);
        }
        $request->update(['status' => 'withdrawn']);
        $this->audit->execute(AuditEventType::AssessmentExclusionWithdrawn, $actor, $request, $request->organization_id, request: request());
        AssessmentCoverageService::bump($request->assessment_cycle_id);
    }

    /**
     * The approved exception is recorded on the snapshot row. It leaves the
     * denominator only when BOTH the reason and the cycle policy say so;
     * otherwise the employee stays eligible and counts as unassessed with an
     * approved exception, so exclusions cannot inflate coverage by default.
     */
    public function applyApprovedExclusion(AssessmentExclusionRequest $request): void
    {
        $row = AssessmentCycleEligibility::query()->lockForUpdate()->findOrFail($request->eligibility_id);
        $reason = AssessmentUnassessedReason::query()->findOrFail($request->reason_id);
        $reduces = $request->cycle->exclusion_reduces_denominator === true && $reason->excludes_from_denominator;
        $row->update(['reason_id' => $reason->id, 'reason_source' => 'approved_exception', 'eligibility_status' => $reduces ? 'excluded' : 'eligible']);
    }

    public function restoreEligibility(User $actor, AssessmentCycleEligibility $row, string $note): void
    {
        if ($row->reason_source !== 'approved_exception') {
            throw ValidationException::withMessages(['eligibility' => 'Only an approved exception can be reversed here.']);
        }
        $old = $row->only(['eligibility_status', 'reason_id', 'reason_source']);
        $row->update(['eligibility_status' => 'eligible', 'reason_id' => null, 'reason_source' => null]);
        $this->audit->execute(AuditEventType::AssessmentEligibilityRestored, $actor, $row, $row->organization_id, $old, ['eligibility_status' => 'eligible'], $note, request());
        AssessmentCoverageService::bump($row->assessment_cycle_id);
    }

    /**
     * Why an employee is (not) in the cycle: the frozen snapshot row next to
     * what the rules would say today, so drift after a transfer is visible.
     *
     * @return array<string, mixed>
     */
    public function explainEligibility(AssessmentCycle $cycle, Employee $employee): array
    {
        $organizationIds = $cycle->organizations()->where('status', 'included')->pluck('organization_id')->map(fn ($id): string => (string) $id)->all();
        $live = $this->resolveEligibility($cycle, [$employee->id], $organizationIds)[$employee->id] ?? null;
        $snapshot = AssessmentCycleEligibility::query()->with('reason:id,code,name_en,name_am,source')
            ->where('assessment_cycle_id', $cycle->id)->where('employee_id', $employee->id)->first();

        return [
            'snapshot' => $snapshot === null ? null : [
                ...$snapshot->only(['eligibility_status', 'organization_id', 'organization_unit_id', 'position_id', 'reason_source', 'form_resolution', 'snapshot_at']),
                'reason' => $snapshot->reason?->only(['code', 'name_en', 'name_am']),
            ],
            'live' => $live,
            'drifted' => $snapshot !== null && $live !== null && ($snapshot->organization_id !== $live['organization_id'] || $snapshot->eligibility_status !== $live['eligibility_status']),
        ];
    }

    /** @return array<string, string> code => id of the active system reasons */
    private function systemReasons(): array
    {
        return AssessmentUnassessedReason::query()->where('source', 'system_detected')->where('is_active', true)->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
    }
}
