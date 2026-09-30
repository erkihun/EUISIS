<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\AssignmentStatus;
use App\Models\EmployeeAssignment;
use App\Models\Grievance;
use App\Models\GrievanceApprovalRule;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceDecision;
use App\Models\GrievanceDelegation;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Executive approval (docs/grievance-management.md §8.3).
 *
 * Whether a decision needs approval, and by whom, comes from configuration:
 * approval rule → approver POSITION → the employee currently assigned to it →
 * their user account, who must also hold grievance_decisions.approve. Acting
 * or delegated authority comes from grievance_delegations. Nothing matches a
 * role name, so a change of office holder needs no workflow change.
 */
final class GrievanceApproverResolver
{
    public const PERMISSION = 'grievance_decisions.approve';

    /**
     * Most specific active rule for a stage/decision type: exact handler >
     * handler type > any, then category, decision type, organization, priority.
     */
    public function ruleFor(Grievance $grievance, GrievanceCaseStage $stage, ?string $decisionType, ?CarbonInterface $on = null): ?GrievanceApprovalRule
    {
        $day = ($on ?? now())->toDateString();
        $organizationId = $stage->organization_id ?? $grievance->organization_id;

        return GrievanceApprovalRule::query()
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->where(fn ($q) => $q->whereNull('handler_type')->orWhere(fn ($h) => $h->where('handler_type', $stage->handler_type?->value)
                ->where(fn ($i) => $i->whereNull('handler_id')->orWhere('handler_id', $stage->handler_id))))
            ->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', $grievance->category_id))
            ->where(fn ($q) => $q->whereNull('decision_type')->orWhere('decision_type', $decisionType))
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->get()
            ->sortBy(fn (GrievanceApprovalRule $r) => [
                $r->handler_id !== null ? 0 : ($r->handler_type !== null ? 1 : 2),
                $r->category_id !== null ? 0 : 1,
                $r->decision_type !== null ? 0 : 1,
                $r->organization_id !== null ? 0 : 1,
                $r->priority,
            ])
            ->first();
    }

    /**
     * @return array{required: bool, rule: GrievanceApprovalRule|null, position_id: string|null, problem: string|null}
     */
    public function requirementFor(Grievance $grievance, GrievanceCaseStage $stage, ?string $decisionType): array
    {
        $rule = $this->ruleFor($grievance, $stage, $decisionType);
        if ($rule !== null) {
            if (! $rule->requires_approval) {
                return ['required' => false, 'rule' => $rule, 'position_id' => null, 'problem' => null];
            }

            return [
                'required' => true,
                'rule' => $rule,
                'position_id' => $rule->approver_position_id,
                'problem' => $rule->approver_position_id === null ? 'grievances.errors.approval_rule_no_position' : null,
            ];
        }

        // No rule: the category may still demand approval, but then someone
        // must configure who approves — never guess.
        if ($grievance->category?->requires_executive_approval) {
            return ['required' => true, 'rule' => null, 'position_id' => null, 'problem' => 'grievances.errors.approval_rule_missing'];
        }

        return ['required' => false, 'rule' => null, 'position_id' => null, 'problem' => null];
    }

    /**
     * Users who may approve for a position right now: its current holders
     * plus active delegates, each holding the approve permission.
     *
     * @return list<array{user: User, delegation: GrievanceDelegation|null}>
     */
    public function approversFor(?string $positionId, ?string $organizationId = null): array
    {
        if ($positionId === null) {
            return [];
        }

        $holders = User::query()
            ->whereIn('employee_id', $this->holderEmployeeIds($positionId))
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $u) => $u->can(self::PERMISSION))
            ->map(fn (User $u) => ['user' => $u, 'delegation' => null])
            ->values()
            ->all();

        $holderIds = array_map(fn (array $a) => $a['user']->getKey(), $holders);
        $delegations = GrievanceDelegation::query()
            ->with('delegate')
            ->where('authority', 'decision_approval')
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->where(fn ($q) => $q->where('position_id', $positionId)->orWhereIn('delegator_user_id', $holderIds))
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->get();

        foreach ($delegations as $delegation) {
            $delegate = $delegation->delegate;
            if ($delegate instanceof User && $delegate->status === 'active' && $delegate->can(self::PERMISSION)) {
                $holders[] = ['user' => $delegate, 'delegation' => $delegation];
            }
        }

        return $holders;
    }

    /**
     * May this user act as approver of the decision now? Returns the
     * delegation used (or null for the holder), or false.
     */
    public function authorityOf(User $user, GrievanceDecision $decision): GrievanceDelegation|false|null
    {
        $organizationId = $decision->stage?->organization_id ?? $decision->grievance?->organization_id;
        foreach ($this->approversFor($decision->approver_position_id, $organizationId) as $entry) {
            if ($entry['user']->is($user)) {
                return $entry['delegation'];
            }
        }

        return false;
    }

    /** Positions the user can approve for (held now, or delegated to them). */
    public function positionsOf(User $user): array
    {
        $positions = [];
        if ($user->employee_id !== null) {
            $positions = EmployeeAssignment::query()
                ->where('employee_id', $user->employee_id)
                ->where('is_current', true)
                ->where('assignment_status', AssignmentStatus::Active->value)
                ->whereNotNull('position_id')
                ->pluck('position_id')->all();
        }

        $delegated = GrievanceDelegation::query()
            ->where('delegate_user_id', $user->getKey())
            ->where('authority', 'decision_approval')
            ->where('status', 'active')
            ->where('starts_at', '<=', now())->where('ends_at', '>=', now())
            ->get(['position_id', 'delegator_user_id']);
        foreach ($delegated as $delegation) {
            if ($delegation->position_id !== null) {
                $positions[] = $delegation->position_id;
            } else {
                $delegatorEmployee = User::query()->whereKey($delegation->delegator_user_id)->value('employee_id');
                if ($delegatorEmployee !== null) {
                    array_push($positions, ...EmployeeAssignment::query()->where('employee_id', $delegatorEmployee)
                        ->where('is_current', true)->where('assignment_status', AssignmentStatus::Active->value)
                        ->whereNotNull('position_id')->pluck('position_id')->all());
                }
            }
        }

        return array_values(array_unique($positions));
    }

    /** @return list<string> */
    private function holderEmployeeIds(string $positionId): array
    {
        return EmployeeAssignment::query()
            ->where('position_id', $positionId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->pluck('employee_id')
            ->all();
    }
}
