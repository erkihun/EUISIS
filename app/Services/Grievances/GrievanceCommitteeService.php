<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AuditEventType;
use App\Enums\CommitteeType;
use App\Enums\Grievance\GrievanceCommitteeRole;
use App\Enums\Grievance\GrievanceRecusalStatus;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceCaseRecusal;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Models\GrievanceStageMember;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grievance committees, their membership history, conflicts of interest and
 * quorum (docs/grievance-management.md §8).
 *
 * Composition rules (size, one Chairperson, one Writer) are settings, not
 * code. Membership is never rewritten: a change ends one term and starts
 * another, so past cases still show who served. Recusals and replacements
 * act on the case panel only, never on the committee.
 */
final class GrievanceCommitteeService
{
    public function __construct(
        private readonly GrievanceSettings $settings,
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceRoutingService $routing,
        private readonly GrievanceCaseAccessService $access,
        private readonly OrganizationScopeService $scope,
        private readonly GrievanceAudit $audit,
        private readonly GrievanceTimeline $timeline,
        private readonly GrievanceNotifier $notifier,
    ) {}

    // ── Committees ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    public function create(User $actor, array $data): GrievanceCommittee
    {
        $this->assertManage($actor, $data['organization_id']);
        $type = CommitteeType::from($data['committee_type']);

        $committee = GrievanceCommittee::query()->create([
            'organization_id' => $data['organization_id'],
            'organization_unit_id' => $data['organization_unit_id'] ?? null,
            'committee_type' => $type,
            'name_en' => $data['name_en'],
            'name_am' => $data['name_am'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'description_am' => $data['description_am'] ?? null,
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
            'effective_to' => $data['effective_to'] ?? null,
            // Grievance committees start pending approval; EPMS panels keep
            // their existing "active on creation" behaviour.
            'status' => in_array($type, [CommitteeType::Grievance, CommitteeType::Tribunal], true) ? 'pending_approval' : 'active',
            'created_by' => $actor->getKey(),
        ]);
        $this->audit->record(AuditEventType::GrievanceCommitteeCreated, $actor, $committee, ['type' => $type->value]);

        return $committee;
    }

    /** @param  array<string, mixed>  $data */
    public function update(GrievanceCommittee $committee, User $actor, array $data): GrievanceCommittee
    {
        $this->assertManage($actor, $committee->organization_id);
        $fields = array_intersect_key($data, array_flip(['name_en', 'name_am', 'description_en', 'description_am', 'effective_to', 'organization_unit_id']));
        $committee->fill($fields)->save();

        if (array_key_exists('status', $data) && $data['status'] === 'inactive' && $committee->status !== 'inactive') {
            if ($committee->stages()->open()->exists()) {
                throw ValidationException::withMessages(['status' => __('grievances.errors.committee_has_open_cases')]);
            }
            $committee->forceFill(['status' => 'inactive', 'effective_to' => $committee->effective_to ?? now()->toDateString()])->save();
        }
        $this->audit->record(AuditEventType::GrievanceCommitteeUpdated, $actor, $committee, array_keys($fields) === [] ? ['status' => $committee->status] : ['fields' => array_keys($fields)]);

        return $committee;
    }

    /** A different user than the creator approves; composition must be valid. */
    public function approve(GrievanceCommittee $committee, User $actor): GrievanceCommittee
    {
        if (! $actor->can('grievance_committees.approve') || ! $this->scope->canExercisePermission($actor, 'grievance_committees.approve', $committee->organization_id)) {
            $this->access->authorize(false);
        }
        if ((int) $committee->created_by === (int) $actor->getKey() && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['committee' => __('grievances.errors.separation_of_duties')]);
        }
        if ($committee->status !== 'pending_approval') {
            throw ValidationException::withMessages(['committee' => __('grievances.errors.stale')]);
        }

        $committee->forceFill(['status' => 'active', 'approved_by' => $actor->getKey(), 'approved_at' => now()]);
        $problems = array_values(array_diff($this->handlers->committeeProblems($committee), ['grievances.errors.committee_not_active']));
        if ($problems !== []) {
            throw ValidationException::withMessages(['committee' => array_map(fn ($p) => __($p), $problems)]);
        }
        $committee->save();
        $this->audit->record(AuditEventType::GrievanceCommitteeApproved, $actor, $committee, ['status' => 'active']);

        return $committee;
    }

    // ── Membership (history-preserving) ──────────────────────────────────────

    /** @param  array{employee_id: string, role: string, effective_from: string, appointment_reference?: string|null}  $data */
    public function addMember(GrievanceCommittee $committee, User $actor, array $data): GrievanceCommitteeMember
    {
        $this->assertManageMembers($actor, $committee);
        $role = GrievanceCommitteeRole::from($data['role']);
        $from = Carbon::parse($data['effective_from'])->startOfDay();

        return DB::transaction(function () use ($committee, $actor, $data, $role, $from): GrievanceCommitteeMember {
            GrievanceCommittee::query()->whereKey($committee->getKey())->lockForUpdate()->first();

            $employee = Employee::query()->find($data['employee_id']);
            if ($employee === null || ($employee->status?->value ?? $employee->status) !== 'active') {
                throw ValidationException::withMessages(['employee_id' => __('grievances.errors.employee_not_active')]);
            }
            if ($committee->members()->servingOn($from)->where('employee_id', $employee->getKey())->exists()) {
                throw ValidationException::withMessages(['employee_id' => __('grievances.errors.already_member')]);
            }

            $serving = $committee->members()->servingOn($from)->get();
            if ($serving->count() + 1 > $this->settings->committeeMaxMembers()) {
                throw ValidationException::withMessages(['employee_id' => __('grievances.committeeMaxMembers')]);
            }
            if ($role === GrievanceCommitteeRole::Chairperson && $serving->contains(fn (GrievanceCommitteeMember $m) => $m->roleEnum() === GrievanceCommitteeRole::Chairperson)) {
                throw ValidationException::withMessages(['role' => __('grievances.committeeAlreadyHasChairperson')]);
            }
            if ($role === GrievanceCommitteeRole::Writer && $serving->contains(fn (GrievanceCommitteeMember $m) => $m->roleEnum() === GrievanceCommitteeRole::Writer)) {
                throw ValidationException::withMessages(['role' => __('grievances.errors.committee_already_has_writer')]);
            }

            $member = GrievanceCommitteeMember::query()->create([
                'committee_id' => $committee->getKey(),
                'employee_id' => $employee->getKey(),
                'role' => $role->value,
                'effective_from' => $from->toDateString(),
                'status' => 'active',
                'appointed_by' => $actor->getKey(),
                'appointment_reference' => $data['appointment_reference'] ?? null,
            ]);
            $this->audit->record(AuditEventType::GrievanceCommitteeMemberAdded, $actor, $committee, ['employee_id' => $employee->getKey(), 'role' => $role->value, 'effective_from' => $from->toDateString()]);

            $this->routing->syncOpenPanels($committee, $actor);

            return $member;
        });
    }

    /**
     * End a term (never a delete). An active committee cannot drop below the
     * configured minimum or lose its Chairperson without a replacement term.
     */
    public function endMember(GrievanceCommittee $committee, GrievanceCommitteeMember $member, User $actor, ?string $reason, ?CarbonInterface $on = null): GrievanceCommitteeMember
    {
        $this->assertManageMembers($actor, $committee);
        if ($member->committee_id !== $committee->getKey()) {
            $this->access->authorize(false);
        }

        return DB::transaction(function () use ($committee, $member, $actor, $reason, $on): GrievanceCommitteeMember {
            GrievanceCommittee::query()->whereKey($committee->getKey())->lockForUpdate()->first();
            $end = Carbon::instance($on ?? now())->startOfDay();

            if ($committee->status === 'active') {
                $remaining = $committee->members()->servingOn(now())->whereKeyNot($member->getKey())->get();
                if ($remaining->count() < $this->settings->committeeMinMembers()) {
                    throw ValidationException::withMessages(['member' => __('grievances.committeeMinMembers')]);
                }
            }

            $member->forceFill([
                'status' => 'inactive',
                'effective_to' => $end->toDateString(),
                'end_reason' => $reason,
            ])->save();
            $this->audit->record(AuditEventType::GrievanceCommitteeMemberEnded, $actor, $committee, ['employee_id' => $member->employee_id, 'effective_to' => $end->toDateString()], null, $reason);

            $this->routing->syncOpenPanels($committee, $actor);

            return $member;
        });
    }

    /**
     * Members who served on a date (for "who sat on this case then").
     *
     * @return list<array{employee_id: string, role: string}>
     */
    public function membersOn(GrievanceCommittee $committee, CarbonInterface $date): array
    {
        return $committee->members()->servingOn($date)->get()
            ->map(fn (GrievanceCommitteeMember $m) => ['employee_id' => $m->employee_id, 'role' => $m->roleEnum()->value])
            ->all();
    }

    // ── Conflict of interest ─────────────────────────────────────────────────

    /** A panel member declares a conflict on the current stage. */
    public function declareRecusal(Grievance $grievance, User $actor, string $reason): GrievanceCaseRecusal
    {
        $stage = $this->access->currentStage($grievance);
        $seat = $this->access->panelSeat($actor, $stage);
        $this->access->authorize($seat !== null);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('grievances.errors.reason_required')]);
        }

        return DB::transaction(function () use ($grievance, $actor, $reason, $stage, $seat): GrievanceCaseRecusal {
            if (GrievanceCaseRecusal::query()->where('case_stage_id', $stage->getKey())->where('employee_id', $seat->employee_id)
                ->whereIn('status', [GrievanceRecusalStatus::Declared->value, GrievanceRecusalStatus::Approved->value])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['reason' => __('grievances.errors.recusal_exists')]);
            }

            $recusal = GrievanceCaseRecusal::query()->create([
                'grievance_id' => $grievance->getKey(),
                'case_stage_id' => $stage->getKey(),
                'committee_id' => $stage->committee_id,
                'stage_member_id' => $seat->getKey(),
                'employee_id' => $seat->employee_id,
                'reason' => $reason,
                'status' => GrievanceRecusalStatus::Declared,
                'declared_by' => $actor->getKey(),
                'declared_at' => now(),
            ]);
            $this->audit->record(AuditEventType::GrievanceRecusalDeclared, $actor, $recusal, ['stage_id' => $stage->getKey()]);
            $this->timeline->record($grievance, 'recusal_declared', $actor, [], $stage->getKey());

            return $recusal;
        });
    }

    /**
     * Approve or reject a recusal. Approval removes the member from the case
     * panel (no further view, vote, signature or minutes edits) and, if the
     * panel falls below quorum, a replacement can be appointed.
     */
    public function decideRecusal(GrievanceCaseRecusal $recusal, User $actor, bool $approve, ?string $notes, ?string $replacementEmployeeId): GrievanceCaseRecusal
    {
        $grievance = $recusal->stage?->grievance;
        $this->access->authorize($grievance !== null && $actor->can('grievances.decide_recusal')
            && $actor->employee_id !== $recusal->employee_id
            && ($this->access->isStageLead($actor, $grievance) || $this->scope->canExercisePermission($actor, 'grievances.decide_recusal', $grievance->organization_id)));

        return DB::transaction(function () use ($recusal, $actor, $approve, $notes, $replacementEmployeeId, $grievance): GrievanceCaseRecusal {
            $recusal = GrievanceCaseRecusal::query()->whereKey($recusal->getKey())->lockForUpdate()->firstOrFail();
            if ($recusal->status !== GrievanceRecusalStatus::Declared) {
                throw ValidationException::withMessages(['recusal' => __('grievances.errors.stale')]);
            }

            $recusal->forceFill([
                'status' => $approve ? GrievanceRecusalStatus::Approved : GrievanceRecusalStatus::Rejected,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_notes' => $notes,
            ])->save();

            if ($approve) {
                $seat = GrievanceStageMember::query()->whereKey($recusal->stage_member_id)->first();
                $seat?->forceFill(['is_active' => false, 'recused_at' => now(), 'left_at' => now()])->save();
                if ($replacementEmployeeId !== null) {
                    $this->appointReplacement($recusal, $actor, $replacementEmployeeId);
                }
            }

            $this->audit->record(AuditEventType::GrievanceRecusalDecided, $actor, $recusal, ['approved' => $approve, 'replacement_employee_id' => $replacementEmployeeId]);
            $this->timeline->record($grievance, $approve ? 'recusal_approved' : 'recusal_rejected', $actor, [], $recusal->case_stage_id);

            return $recusal->refresh();
        });
    }

    /** A replacement sits on this case only; the committee itself is unchanged. */
    public function appointReplacement(GrievanceCaseRecusal $recusal, User $actor, string $employeeId): GrievanceStageMember
    {
        $stage = GrievanceCaseStage::query()->with('grievance')->findOrFail($recusal->case_stage_id);
        $employee = Employee::query()->find($employeeId);
        if ($employee === null || $employee->getKey() === $stage->grievance->employee_id || $employee->getKey() === $stage->grievance->respondent_employee_id) {
            throw ValidationException::withMessages(['replacement_employee_id' => __('grievances.errors.replacement_not_eligible')]);
        }
        if ($stage->members()->where('employee_id', $employeeId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['replacement_employee_id' => __('grievances.errors.already_member')]);
        }

        $original = GrievanceStageMember::query()->find($recusal->stage_member_id);
        $seat = GrievanceStageMember::query()->updateOrCreate(
            ['case_stage_id' => $stage->getKey(), 'employee_id' => $employeeId],
            [
                'role' => $original?->role ?? GrievanceCommitteeRole::Member,
                'source' => 'replacement',
                'is_active' => true,
                'joined_at' => now(),
                'left_at' => null,
                'recused_at' => null,
                'replaces_stage_member_id' => $original?->getKey(),
                'added_by' => $actor->getKey(),
            ],
        );
        $recusal->forceFill(['replacement_employee_id' => $employeeId, 'replacement_stage_member_id' => $seat->getKey()])->save();
        $this->audit->record(AuditEventType::GrievanceMemberReplaced, $actor, $recusal, ['replacement_employee_id' => $employeeId, 'replaces' => $original?->employee_id]);

        return $seat;
    }

    // ── Quorum ───────────────────────────────────────────────────────────────

    /**
     * Quorum of a committee stage under the configured rule. Participants are
     * the votes cast when voting is on, otherwise the active, non-recused
     * panel. The base is the committee's panel size (never below the
     * configured minimum).
     *
     * @return array{rule: string, required: int, present: int, met: bool}
     */
    public function quorum(GrievanceCaseStage $stage, ?int $votesCast = null): array
    {
        $rule = $this->settings->quorumRule();
        $active = $stage->members()->where('is_active', true)->whereNull('recused_at')->count();
        $base = max($this->settings->committeeMinMembers(), $stage->members()->where('source', 'committee')->count());
        $present = $votesCast ?? $active;

        $required = match ($rule) {
            'majority' => intdiv($base, 2) + 1,
            'all' => $base,
            'fixed_count' => $this->settings->quorumFixedCount(),
            default => 0,
        };

        return ['rule' => $rule, 'required' => $required, 'present' => $present, 'met' => $present >= $required];
    }

    private function assertManage(User $actor, ?string $organizationId): void
    {
        $this->access->authorize($actor->can('grievance_committees.manage') && $this->scope->canExercisePermission($actor, 'grievance_committees.manage', $organizationId));
    }

    private function assertManageMembers(User $actor, GrievanceCommittee $committee): void
    {
        $this->access->authorize($actor->can('grievance_committee_members.manage') && $this->scope->canExercisePermission($actor, 'grievance_committee_members.manage', $committee->organization_id));
    }
}
