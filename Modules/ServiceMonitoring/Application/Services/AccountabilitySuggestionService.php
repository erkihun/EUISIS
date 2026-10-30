<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Enums\Discipline\DisciplinaryCaseStatus;
use App\Enums\Discipline\DisciplinaryHeadAction;
use App\Enums\Discipline\DisciplinaryPenalty;
use App\Enums\HierarchyVersionStatus;
use App\Models\DisciplinaryDecision;
use App\Models\HierarchyVersion;
use App\Models\OrganizationClosurePath;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Section I suggestions from the Discipline module (D16), read-only.
 *
 * A "final" decision is the head of institution's latest decision on a case
 * (Art. 28) that approves or modifies a penalty and has been DELIVERED
 * (Art. 28(3): generating a letter is not delivery), on a case whose status
 * is decision_delivered or closed. The delivery date is the action date and
 * must fall in the quarter. Cases of the participant's organization and its
 * published-hierarchy descendants qualify, limited to the viewer's scope.
 *
 * Removal from responsibility and legal action are never suggested: the
 * Discipline module does not record them, so they are entered by hand.
 */
class AccountabilitySuggestionService
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public static function finalActionFor(DisciplinaryPenalty $penalty): FinalAction
    {
        return match ($penalty) {
            DisciplinaryPenalty::OralWarning, DisciplinaryPenalty::WrittenWarning => FinalAction::Warning,
            DisciplinaryPenalty::SalaryFineDays, DisciplinaryPenalty::SalaryFineMonths => FinalAction::SalaryFine,
            DisciplinaryPenalty::Demotion => FinalAction::Demotion,
            DisciplinaryPenalty::Dismissal => FinalAction::Dismissal,
        };
    }

    /**
     * @return list<array{decision_id: string, employee_id: string, employee_name: ?string, final_action: string, penalty: string, penalty_amount: ?int, action_date: string, role_category: ?string, case_number: ?string, used: bool}>
     */
    public function suggestionsFor(Submission $submission, User $viewer): array
    {
        $used = AccountabilityEntry::query()->where('submission_id', $submission->id)->whereNotNull('source_decision_id')->pluck('source_decision_id')->map(fn ($id): string => (string) $id)->all();

        return $this->query($submission, $viewer)->get()->map(function (DisciplinaryDecision $decision) use ($used): array {
            $case = $decision->disciplinaryCase;
            $position = $case?->position;

            return [
                'decision_id' => (string) $decision->id,
                'employee_id' => (string) $case?->accused_employee_id,
                'employee_name' => $case?->accused?->full_name,
                'final_action' => self::finalActionFor($decision->penalty)->value,
                'penalty' => $decision->penalty->value,
                'penalty_amount' => $decision->penalty_amount !== null ? (int) $decision->penalty_amount : null,
                'action_date' => $decision->delivered_at->setTimezone('Africa/Addis_Ababa')->toDateString(),
                'role_category' => $position === null ? null : ($position->is_unit_head ? RoleCategory::MeritLeader->value : RoleCategory::Professional->value),
                'case_number' => $case?->case_number,
                'used' => in_array((string) $decision->id, $used, true),
            ];
        })->values()->all();
    }

    public function find(Submission $submission, User $viewer, string $decisionId): ?DisciplinaryDecision
    {
        return $this->query($submission, $viewer)->whereKey($decisionId)->first();
    }

    /** @return Builder<DisciplinaryDecision> */
    private function query(Submission $submission, User $viewer): Builder
    {
        $period = $submission->period;
        $organizations = $this->subtree($submission->organization_id);
        if (! $this->scope->isUnrestricted($viewer)) {
            $organizations = array_values(array_intersect($organizations, $this->scope->allowedOrganizationIds($viewer)));
        }
        $from = $period->starts_on->toDateString().' 00:00:00';
        $to = $period->ends_on->toDateString().' 23:59:59';

        return DisciplinaryDecision::query()
            ->with(['disciplinaryCase.accused:id,full_name,first_name,last_name', 'disciplinaryCase.position:id,is_unit_head'])
            ->whereIn('action', [DisciplinaryHeadAction::Approve->value, DisciplinaryHeadAction::Modify->value])
            ->whereNotNull('penalty')
            ->whereNotNull('delivered_at')
            // Delivery moments are stored in UTC; the quarter is in Addis Ababa (UTC+3).
            ->whereBetween('delivered_at', [
                CarbonImmutable::parse($from, 'Africa/Addis_Ababa')->utc()->toDateTimeString(),
                CarbonImmutable::parse($to, 'Africa/Addis_Ababa')->utc()->toDateTimeString(),
            ])
            ->whereHas('disciplinaryCase', fn (Builder $q): Builder => $q->whereIn('organization_id', $organizations)
                ->whereIn('status', [DisciplinaryCaseStatus::DecisionDelivered->value, DisciplinaryCaseStatus::Closed->value]))
            // Only the latest decision of a case is final.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('disciplinary_decisions as later')
                ->whereColumn('later.case_id', 'disciplinary_decisions.case_id')
                ->whereColumn('later.decided_at', '>', 'disciplinary_decisions.decided_at'))
            ->orderBy('delivered_at');
    }

    /** @return list<string> the organization and its descendants in the published hierarchy */
    private function subtree(string $organizationId): array
    {
        $version = HierarchyVersion::query()->where('status', HierarchyVersionStatus::Published->value)->latest('approval_date')->value('id');
        $ids = $version === null ? [] : OrganizationClosurePath::query()->where('hierarchy_version_id', $version)
            ->where('ancestor_organization_id', $organizationId)->pluck('descendant_organization_id')->map(fn ($id): string => (string) $id)->all();

        return array_values(array_unique([$organizationId, ...$ids]));
    }
}
