<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\Grievance\GrievanceCommitteeRole;
use App\Enums\Grievance\GrievanceConfidentiality;
use App\Enums\Grievance\GrievanceDecisionStatus;
use App\Enums\Grievance\GrievanceHandlerType;
use App\Enums\Grievance\GrievanceLetterStatus;
use App\Enums\GrievanceStatus;
use App\Models\Employee;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceDecision;
use App\Models\GrievanceEvidence;
use App\Models\GrievanceLetter;
use App\Models\GrievanceStageMember;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what on a grievance case (docs/grievance-management.md §7).
 * Server-side only; the frontend mirrors the `can` map it is given.
 *
 * A permission is never enough on its own. Case authority comes from exactly
 * one of these relationships, combined with the matching permission:
 *
 *   complainant   the user filed it / is the complainant employee
 *   panel         active, non-recused member of the stage's committee panel
 *   officer       explicitly assigned case officer at a unit stage
 *   assigner      placed (by HR assignment) in the handling unit and holds
 *                 grievances.assign — sees and distributes the unit's cases
 *   external      grievances.tribunal for external-authority stages
 *   approver      currently resolved approver of one of the case's decisions
 *   intake        grievances.intake_review in organization scope, while the
 *                 case is at intake
 *   oversight     grievances.oversight_view in organization scope; read-only;
 *                 highly restricted cases show metadata only
 *
 * A routed case never widens the user's organization scope: access is to
 * this case only. The complainant and a named respondent never handle it.
 */
final class GrievanceCaseAccessService
{
    public const INTAKE_STATUSES = [GrievanceStatus::Submitted, GrievanceStatus::IntakeReview];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceApproverResolver $approvers,
        private readonly GrievanceSettings $settings,
    ) {}

    public function employeeOf(User $user): ?Employee
    {
        $employee = $user->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function isComplainant(User $user, Grievance $grievance): bool
    {
        return $grievance->isOwnedBy($user);
    }

    public function isConflicted(User $user, Grievance $grievance): bool
    {
        if ($this->isComplainant($user, $grievance)) {
            return true;
        }

        return $user->employee_id !== null && $grievance->respondent_employee_id === $user->employee_id;
    }

    /** Active, non-recused panel seat of the user on this stage. */
    public function panelSeat(User $user, ?GrievanceCaseStage $stage, bool $includeInactive = false): ?GrievanceStageMember
    {
        if ($stage === null || $user->employee_id === null || $stage->handler_type !== GrievanceHandlerType::Committee) {
            return null;
        }

        return $stage->members()
            ->where('employee_id', $user->employee_id)
            ->when(! $includeInactive, fn ($q) => $q->eligible())
            ->first();
    }

    public function panelRole(User $user, ?GrievanceCaseStage $stage): ?GrievanceCommitteeRole
    {
        return $this->panelSeat($user, $stage)?->role;
    }

    public function isCaseOfficer(User $user, ?GrievanceCaseStage $stage): bool
    {
        return $stage !== null && $stage->activeOfficers()->where('user_id', $user->getKey())->exists();
    }

    /** Placed in the handling unit (or beneath it) with grievances.assign. */
    public function isUnitAssigner(User $user, ?GrievanceCaseStage $stage): bool
    {
        return $stage !== null
            && $stage->handler_type === GrievanceHandlerType::OrganizationUnit
            && $user->can('grievances.assign')
            && in_array((string) $stage->handler_id, $this->unitIdsOf($user), true);
    }

    private function isUnitStaff(User $user, ?GrievanceCaseStage $stage): bool
    {
        return $stage !== null
            && $stage->handler_type === GrievanceHandlerType::OrganizationUnit
            && $this->settings->unitStaffSeeAllCases()
            && $user->can('grievances.view_assigned')
            && in_array((string) $stage->handler_id, $this->unitIdsOf($user), true);
    }

    private function isExternalHandler(User $user, ?GrievanceCaseStage $stage): bool
    {
        return $stage !== null && $stage->handler_type === GrievanceHandlerType::ExternalAuthority && $user->can('grievances.tribunal');
    }

    /** The user currently handles this stage (any handler relationship). */
    public function handlesStage(User $user, ?GrievanceCaseStage $stage): bool
    {
        if ($stage === null) {
            return false;
        }
        $grievance = $stage->relationLoaded('grievance') ? $stage->grievance : Grievance::query()->find($stage->grievance_id);
        if ($grievance === null || $this->isConflicted($user, $grievance)) {
            return false;
        }

        if ($this->isExternalHandler($user, $stage)) {
            return true;
        }
        if (! $user->can('grievances.view_assigned') && ! $user->can('grievances.assign')) {
            return false;
        }

        return $this->panelSeat($user, $stage) !== null
            || $this->isCaseOfficer($user, $stage)
            || $this->isUnitAssigner($user, $stage)
            || $this->isUnitStaff($user, $stage);
    }

    public function handlesCurrent(User $user, Grievance $grievance): bool
    {
        return $grievance->current_stage_id !== null
            && ! $grievance->isFinal()
            && $this->handlesStage($user, $this->currentStage($grievance));
    }

    /** Handled any earlier stage (read-only access to the case history). */
    public function handledAnyStage(User $user, Grievance $grievance): bool
    {
        if ($this->isConflicted($user, $grievance)) {
            return false;
        }

        return $grievance->stages()->where(function (Builder $q) use ($user): void {
            $q->whereHas('members', fn ($m) => $m->whereIn('employee_id', array_filter([$user->employee_id]))->whereNull('recused_at'))
                ->orWhereHas('officers', fn ($o) => $o->where('user_id', $user->getKey()));
        })->exists() && ($user->can('grievances.view_assigned') || $user->can('grievances.assign'));
    }

    public function isIntakeOfficer(User $user, Grievance $grievance): bool
    {
        return in_array($grievance->status, self::INTAKE_STATUSES, true)
            && ! $this->isConflicted($user, $grievance)
            && $this->scope->canExercisePermission($user, 'grievances.intake_review', $grievance->organization_id);
    }

    public function isOversight(User $user, Grievance $grievance): bool
    {
        if ($this->isComplainant($user, $grievance)) {
            return false;
        }

        return $user->isSuperAdmin()
            || $this->scope->canExercisePermission($user, 'grievances.oversight_view', $grievance->organization_id);
    }

    /** Resolved approver of a pending decision, or the recorded approver of a past one. */
    public function isApproverOf(User $user, Grievance $grievance): bool
    {
        if ($this->isConflicted($user, $grievance) || ! $user->can(GrievanceApproverResolver::PERMISSION)) {
            return false;
        }

        foreach ($grievance->decisions()->with(['stage', 'grievance'])->get() as $decision) {
            if ((int) $decision->approved_by === (int) $user->getKey() || $decision->approvals()->where('actor_user_id', $user->getKey())->exists()) {
                return true;
            }
            if ($this->isPendingApproval($decision) && $this->approvers->authorityOf($user, $decision) !== false) {
                return true;
            }
        }

        return false;
    }

    // ── Case-level abilities ─────────────────────────────────────────────────

    public function canView(User $user, Grievance $grievance): bool
    {
        if ($this->isComplainant($user, $grievance)) {
            return $user->can('grievances.view_own');
        }

        return $this->handlesCurrent($user, $grievance)
            || $this->handledAnyStage($user, $grievance)
            || $this->isIntakeOfficer($user, $grievance)
            || $this->isApproverOf($user, $grievance)
            || $this->isOversight($user, $grievance);
    }

    /** Full content (description, evidence, decisions). Oversight of a highly restricted case sees metadata only. */
    public function canViewDetails(User $user, Grievance $grievance): bool
    {
        if (! $this->canView($user, $grievance)) {
            return false;
        }
        if ($this->isComplainant($user, $grievance)) {
            return true;
        }
        $onlyOversight = ! $this->handlesCurrent($user, $grievance)
            && ! $this->handledAnyStage($user, $grievance)
            && ! $this->isIntakeOfficer($user, $grievance)
            && ! $this->isApproverOf($user, $grievance);

        return ! ($onlyOversight && $grievance->confidentiality_level === GrievanceConfidentiality::HighlyRestricted);
    }

    /** Internal handler view (notes, internal timeline, drafts) — never the complainant. */
    public function canSeeInternal(User $user, Grievance $grievance): bool
    {
        return ! $this->isComplainant($user, $grievance) && $this->canViewDetails($user, $grievance);
    }

    public function canSeeInternalNotes(User $user, Grievance $grievance): bool
    {
        return $this->canSeeInternal($user, $grievance);
    }

    /** Act on the current stage (the case must be open). */
    public function canHandle(User $user, Grievance $grievance, string $permission = 'grievances.review'): bool
    {
        return $user->can($permission) && $this->handlesCurrent($user, $grievance);
    }

    public function canReview(User $user, Grievance $grievance): bool
    {
        return $this->canHandle($user, $grievance, 'grievances.review') && ! $this->hasPendingRecusal($user, $grievance);
    }

    public function canUpdate(User $user, Grievance $grievance): bool
    {
        return $this->canReview($user, $grievance);
    }

    public function canIntake(User $user, Grievance $grievance): bool
    {
        return $user->can('grievances.intake_review') && $this->isIntakeOfficer($user, $grievance);
    }

    /** Assign case officers: the unit's assigning officer. */
    public function canAssignOfficers(User $user, Grievance $grievance): bool
    {
        $stage = $this->currentStage($grievance);

        return ! $grievance->isFinal() && ! $this->isConflicted($user, $grievance) && $this->isUnitAssigner($user, $stage);
    }

    /** Draft or revise the decision: committee writer/chair, or a unit case officer/assigner. */
    public function canDraftDecision(User $user, Grievance $grievance): bool
    {
        if (! $this->canHandle($user, $grievance, 'grievance_decisions.create') || $this->hasPendingRecusal($user, $grievance)) {
            return false;
        }
        $stage = $this->currentStage($grievance);

        return match ($stage?->handler_type) {
            GrievanceHandlerType::Committee => in_array($this->panelRole($user, $stage), [GrievanceCommitteeRole::Writer, GrievanceCommitteeRole::Chairperson], true),
            GrievanceHandlerType::OrganizationUnit => $this->isCaseOfficer($user, $stage) || $this->isUnitAssigner($user, $stage),
            GrievanceHandlerType::ExternalAuthority => $this->isExternalHandler($user, $stage),
            default => false,
        };
    }

    /** Lead the stage: committee chairperson, or the unit's assigning officer (unit head). */
    public function isStageLead(User $user, Grievance $grievance): bool
    {
        $stage = $this->currentStage($grievance);
        if ($stage === null || ! $this->handlesCurrent($user, $grievance)) {
            return false;
        }

        return match ($stage->handler_type) {
            GrievanceHandlerType::Committee => $this->panelRole($user, $stage) === GrievanceCommitteeRole::Chairperson,
            GrievanceHandlerType::OrganizationUnit => $this->isUnitAssigner($user, $stage) || $stage->activeOfficers()->where('user_id', $user->getKey())->where('role', 'lead')->exists(),
            GrievanceHandlerType::ExternalAuthority => $this->isExternalHandler($user, $stage),
            default => false,
        };
    }

    public function canLead(User $user, Grievance $grievance, string $permission): bool
    {
        return $user->can($permission) && $this->isStageLead($user, $grievance) && ! $this->hasPendingRecusal($user, $grievance);
    }

    /** Hearings and minutes: committee writer/chair, or a unit case officer/assigner, with the permission. */
    public function canManageHearings(User $user, Grievance $grievance): bool
    {
        return $this->canDraftDecisionLike($user, $grievance, 'grievance_hearings.manage');
    }

    public function canPrepareLetters(User $user, Grievance $grievance): bool
    {
        return $this->canDraftDecisionLike($user, $grievance, 'grievance_correspondence.create');
    }

    public function canSignLetter(User $user, GrievanceLetter $letter): bool
    {
        $grievance = $letter->grievance;
        if ($grievance === null || ! $user->can('grievance_correspondence.sign')
            || $this->isConflicted($user, $grievance) || $this->hasPendingRecusal($user, $grievance)
            || $letter->case_stage_id !== $grievance->current_stage_id) {
            return false;
        }

        if ($this->handlesCurrent($user, $grievance)) {
            return true;
        }

        $decision = $letter->decision;

        return $decision !== null && $decision->case_stage_id === $grievance->current_stage_id
            && in_array($decision->status, [GrievanceDecisionStatus::Finalized, GrievanceDecisionStatus::Issued], true)
            && $this->approvers->authorityOf($user, $decision) !== false;
    }

    public function canApprove(User $user, GrievanceDecision $decision): bool
    {
        $grievance = $decision->grievance;
        if ($grievance === null || ! $this->isPendingApproval($decision) || $this->isConflicted($user, $grievance)) {
            return false;
        }
        // Separation of duties: the preparer and the stage's handlers never approve.
        if ((int) $decision->prepared_by === (int) $user->getKey() && ! $this->settings->allowSelfApproval()) {
            return false;
        }
        if ($this->handlesStage($user, $decision->stage) && ! $this->settings->allowSelfApproval()) {
            return false;
        }

        return $this->approvers->authorityOf($user, $decision) !== false;
    }

    public function isPendingApproval(GrievanceDecision $decision): bool
    {
        return in_array($decision->status, [GrievanceDecisionStatus::PendingExecutiveApproval, GrievanceDecisionStatus::Resubmitted], true);
    }

    public function canViewEvidence(User $user, GrievanceEvidence $evidence): bool
    {
        $grievance = $evidence->grievance;
        if ($grievance === null) {
            return false;
        }
        if ($this->isComplainant($user, $grievance)) {
            // The complainant sees only what they submitted themselves.
            return $user->can('grievances.view_own')
                && ($evidence->submitted_by_complainant || (int) $evidence->submitted_by === (int) $user->getKey());
        }
        if (! $this->canViewDetails($user, $grievance)) {
            return false;
        }
        // Highly restricted evidence: handlers and approvers only.
        if ($evidence->classification === GrievanceConfidentiality::HighlyRestricted) {
            return $this->handlesCurrent($user, $grievance) || $this->handledAnyStage($user, $grievance) || $this->isApproverOf($user, $grievance);
        }

        return true;
    }

    public function canDownloadDocument(User $user, GrievanceLetter $letter): bool
    {
        $grievance = $letter->grievance;
        if ($grievance === null) {
            return false;
        }
        if ($this->isComplainant($user, $grievance)) {
            // Only finalized official documents, never internal drafts.
            return $user->can('grievances.view_own')
                && $letter->status === GrievanceLetterStatus::Issued
                && $letter->visible_to_complainant;
        }
        if ($letter->status === GrievanceLetterStatus::Draft) {
            return $this->canPrepareLetters($user, $grievance);
        }

        return $user->can('grievance_correspondence.view') && $this->canViewDetails($user, $grievance)
            || $this->isRegistryOfficer($user, $letter);
    }

    /** Registry officer: letters (not cases) of organizations in scope, for sealing and dispatch. */
    public function isRegistryOfficer(User $user, GrievanceLetter $letter): bool
    {
        return $letter->status !== GrievanceLetterStatus::Draft
            && ($user->can('grievance_correspondence.apply_seal') || $user->can('grievance_correspondence.issue'))
            && $this->scope->canExercisePermission($user, 'grievance_correspondence.view', $letter->organization_id ?? $letter->grievance?->organization_id);
    }

    public function authorize(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException(__('grievances.errors.forbidden'));
        }
    }

    // ── Query constraints for lists ──────────────────────────────────────────

    /**
     * Cases the user currently handles (their work queue).
     *
     * @param  Builder<Grievance>  $query
     * @return Builder<Grievance>
     */
    public function constrainAssigned(Builder $query, User $user): Builder
    {
        $employeeId = $user->employee_id;
        $canView = $user->can('grievances.view_assigned');
        $canAssign = $user->can('grievances.assign');
        $unitIds = ($canAssign || ($canView && $this->settings->unitStaffSeeAllCases())) ? $this->unitIdsOf($user) : [];
        $tribunal = $user->can('grievances.tribunal');

        if (! $canView && ! $canAssign && ! $tribunal) {
            return $query->whereRaw('1 = 0');
        }

        $this->excludeConflicts($query, $user);

        return $query
            ->whereNotIn($query->qualifyColumn('status'), [GrievanceStatus::Closed->value, GrievanceStatus::Withdrawn->value, GrievanceStatus::RejectedAtIntake->value, GrievanceStatus::Draft->value])
            ->where(function (Builder $q) use ($employeeId, $user, $canView, $unitIds, $tribunal): void {
                $q->whereRaw('1 = 0');
                if ($canView && $employeeId !== null) {
                    $q->orWhereHas('currentStage.members', fn ($m) => $m->where('employee_id', $employeeId)->eligible());
                }
                if ($canView) {
                    $q->orWhereHas('currentStage.officers', fn ($o) => $o->where('user_id', $user->getKey())->whereNull('released_at'));
                }
                if ($unitIds !== []) {
                    $q->orWhere(fn ($u) => $u->where('current_handler_type', GrievanceHandlerType::OrganizationUnit->value)->whereIn('current_handler_id', $unitIds));
                }
                if ($tribunal) {
                    $q->orWhere('current_handler_type', GrievanceHandlerType::ExternalAuthority->value);
                }
            });
    }

    /**
     * Every case the user may open: current and past handling, intake in
     * scope, pending approvals, and oversight scope. Never the user's own.
     *
     * @param  Builder<Grievance>  $query
     * @return Builder<Grievance>
     */
    public function constrainAuthorized(Builder $query, User $user): Builder
    {
        $employeeId = $user->employee_id;
        $handles = $user->can('grievances.view_assigned') || $user->can('grievances.assign');
        $oversight = $user->isSuperAdmin() || $user->can('grievances.oversight_view');
        $intake = $user->can('grievances.intake_review');
        $approver = $user->can(GrievanceApproverResolver::PERMISSION);
        $positions = $approver ? $this->approvers->positionsOf($user) : [];

        $assignedIds = $this->constrainAssigned(Grievance::query(), $user)->select('grievances.id');

        $this->excludeConflicts($query, $user);

        return $query->where(function (Builder $q) use ($assignedIds, $employeeId, $user, $handles, $oversight, $intake, $approver, $positions): void {
            $q->whereIn('grievances.id', $assignedIds);

            if ($handles) {
                $q->orWhereHas('stages', fn ($s) => $s->where(function ($s2) use ($employeeId, $user): void {
                    $s2->whereHas('members', fn ($m) => $m->whereIn('employee_id', array_filter([$employeeId]))->whereNull('recused_at'))
                        ->orWhereHas('officers', fn ($o) => $o->where('user_id', $user->getKey()));
                }));
            }
            if ($intake) {
                $q->orWhere(function (Builder $i) use ($user): void {
                    $i->whereIn('status', array_map(fn ($s) => $s->value, self::INTAKE_STATUSES));
                    $this->applyPermissionScope($i, $user, 'grievances.intake_review');
                });
            }
            if ($approver) {
                $q->orWhereHas('decisions', fn ($d) => $d->where('approved_by', $user->getKey())
                    ->orWhereHas('approvals', fn ($a) => $a->where('actor_user_id', $user->getKey()))
                    ->orWhere(fn ($p) => $p->whereIn('status', [GrievanceDecisionStatus::PendingExecutiveApproval->value, GrievanceDecisionStatus::Resubmitted->value])
                        ->whereIn('approver_position_id', $positions)));
            }
            if ($oversight) {
                $q->orWhere(function (Builder $o) use ($user): void {
                    $o->where('status', '!=', GrievanceStatus::Draft->value);
                    if (! $user->isSuperAdmin()) {
                        $this->applyPermissionScope($o, $user, 'grievances.oversight_view');
                    }
                });
            }
        });
    }

    /** Organization scope for a scoped permission (unrestricted users: no filter). */
    public function applyPermissionScope(Builder $query, User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            $query->whereRaw('1 = 0');

            return;
        }
        if ($this->scope->isUnrestricted($user)) {
            return;
        }
        $query->whereIn($query->qualifyColumn('organization_id'), $this->scope->allowedOrganizationIds($user));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function currentStage(Grievance $grievance): ?GrievanceCaseStage
    {
        if ($grievance->current_stage_id === null) {
            return null;
        }

        $stage = $grievance->relationLoaded('currentStage') ? $grievance->currentStage : $grievance->currentStage()->first();
        if ($stage !== null && ! $stage->relationLoaded('grievance')) {
            $stage->setRelation('grievance', $grievance);
        }

        return $stage;
    }

    public function hasPendingRecusal(User $user, Grievance $grievance): bool
    {
        return $user->employee_id !== null && $grievance->current_stage_id !== null
            && $grievance->recusals()->where('case_stage_id', $grievance->current_stage_id)
                ->where('employee_id', $user->employee_id)->whereIn('status', ['declared', 'approved'])->exists();
    }

    /** @return list<string> */
    public function unitIdsOf(User $user): array
    {
        return $this->handlers->placementOf($this->employeeOf($user))['unit_ids'];
    }

    private function canDraftDecisionLike(User $user, Grievance $grievance, string $permission): bool
    {
        if (! $this->canHandle($user, $grievance, $permission) || $this->hasPendingRecusal($user, $grievance)) {
            return false;
        }
        $stage = $this->currentStage($grievance);

        return match ($stage?->handler_type) {
            GrievanceHandlerType::Committee => in_array($this->panelRole($user, $stage), [GrievanceCommitteeRole::Writer, GrievanceCommitteeRole::Chairperson], true),
            GrievanceHandlerType::OrganizationUnit => $this->isCaseOfficer($user, $stage) || $this->isUnitAssigner($user, $stage),
            GrievanceHandlerType::ExternalAuthority => $this->isExternalHandler($user, $stage),
            default => false,
        };
    }

    /** @param  Builder<Grievance>  $query */
    private function excludeConflicts(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('submitted_by_user_id'))->orWhere($q->qualifyColumn('submitted_by_user_id'), '!=', $user->getKey()));
        if ($user->employee_id !== null) {
            $query->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('employee_id'))->orWhere($q->qualifyColumn('employee_id'), '!=', $user->employee_id));
            $query->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('respondent_employee_id'))->orWhere($q->qualifyColumn('respondent_employee_id'), '!=', $user->employee_id));
        }
    }
}
