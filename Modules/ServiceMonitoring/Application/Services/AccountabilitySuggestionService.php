<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Backbone\Contracts\OrganizationHierarchy;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Carbon\CarbonImmutable;
use Modules\Discipline\Contracts\Data\DisciplinaryPenalty;
use Modules\Discipline\Contracts\DisciplineCases;
use Modules\ServiceMonitoring\Domain\Enums\FinalAction;
use Modules\ServiceMonitoring\Domain\Enums\RoleCategory;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Section I suggestions from the Discipline module (D16), read-only, through
 * its DisciplineCases contract.
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
    public function __construct(private readonly OrganizationScopeService $scope, private readonly DisciplineCases $cases, private readonly OrganizationHierarchy $hierarchy) {}

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

        return array_map(fn (array $decision): array => [
            'decision_id' => $decision['decision_id'],
            'employee_id' => (string) $decision['employee_id'],
            'employee_name' => $decision['employee_name'],
            'final_action' => self::finalActionFor($decision['penalty'])->value,
            'penalty' => $decision['penalty']->value,
            'penalty_amount' => $decision['penalty_amount'],
            'action_date' => self::actionDate($decision),
            'role_category' => $decision['is_unit_head'] === null ? null : ($decision['is_unit_head'] ? RoleCategory::MeritLeader->value : RoleCategory::Professional->value),
            'case_number' => $decision['case_number'],
            'used' => in_array($decision['decision_id'], $used, true),
        ], $this->decisions($submission, $viewer));
    }

    /**
     * One suggestible decision, or null when it is not final, outside the quarter or outside the viewer's scope.
     *
     * @return array{decision_id: string, case_number: ?string, employee_id: ?string, employee_name: ?string, is_unit_head: ?bool, penalty: DisciplinaryPenalty, penalty_amount: ?int, delivered_at: CarbonImmutable}|null
     */
    public function find(Submission $submission, User $viewer, string $decisionId): ?array
    {
        return $this->decisions($submission, $viewer, $decisionId)[0] ?? null;
    }

    /** The delivery date in Addis Ababa, which is the Section I action date. */
    public static function actionDate(array $decision): string
    {
        return $decision['delivered_at']->setTimezone('Africa/Addis_Ababa')->toDateString();
    }

    /** @return list<array{decision_id: string, case_number: ?string, employee_id: ?string, employee_name: ?string, is_unit_head: ?bool, penalty: DisciplinaryPenalty, penalty_amount: ?int, delivered_at: CarbonImmutable}> */
    private function decisions(Submission $submission, User $viewer, ?string $decisionId = null): array
    {
        $period = $submission->period;
        // The organization and its descendants in the default (published) structure.
        $organizations = array_values(array_unique([(string) $submission->organization_id, ...$this->hierarchy->subtreeIds((string) $submission->organization_id)]));
        if (! $this->scope->isUnrestricted($viewer)) {
            $organizations = array_values(array_intersect($organizations, $this->scope->allowedOrganizationIds($viewer)));
        }

        // Delivery moments are stored in UTC; the quarter is in Addis Ababa (UTC+3).
        return $this->cases->finalDecisionsDelivered(
            $organizations,
            CarbonImmutable::parse($period->starts_on->toDateString().' 00:00:00', 'Africa/Addis_Ababa'),
            CarbonImmutable::parse($period->ends_on->toDateString().' 23:59:59', 'Africa/Addis_Ababa'),
            $decisionId,
        );
    }
}
