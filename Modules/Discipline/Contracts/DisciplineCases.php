<?php

declare(strict_types=1);

namespace Modules\Discipline\Contracts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Discipline\Contracts\Data\DisciplinaryPenalty;

/**
 * What other modules ask of discipline cases (ADR 0017): the statutory
 * committee rules, the employee's court filing, and the statutory deadline
 * alerts. Cases are named by id; nothing outside writes them.
 */
interface DisciplineCases
{
    /**
     * Whether the employee was found responsible and penalised on or after
     * $since by a decision the Administrative Court has not set aside
     * (Regulation 201, Art. 6(2)).
     */
    public function penalisedSince(string $employeeId, Carbon $since): bool;

    /**
     * The employee's own cases whose decision was delivered to them (the ones a
     * case at the tribunal may challenge), newest first.
     *
     * @return list<array{id: string, case_number: string, summary: ?string, status: ?string, delivered_at: ?string}>
     */
    public function deliveredCasesOf(string $employeeId, int $limit = 50): array;

    /**
     * Of the given case ids, those that are the employee's delivered cases.
     *
     * @param  list<string>  $caseIds
     * @return list<string>
     */
    public function ownDeliveredCaseIds(string $employeeId, array $caseIds): array;

    /**
     * What court-ms keeps of each case, in the grievances' form: the charges,
     * the employee's answers (as responses) and the delivered decisions, as
     * plain text; oldest charge first.
     *
     * @param  list<string>  $caseIds
     * @return list<array<string, mixed>>
     */
    public function courtSnapshots(array $caseIds): array;

    /**
     * Who a statutory deadline on the case alerts: its number, its page and the
     * case managers who may act on it; nobody once the case is final.
     *
     * @return array{0: ?string, 1: ?string, 2: list<User>}
     */
    public function deadlineAlertTarget(string $caseId): array;

    /**
     * Final decisions delivered from $from to $to on cases of the given
     * organizations (Service Delivery Monitoring, Section I): the head's
     * latest decision on a case that approves or modifies a penalty and was
     * delivered (Art. 28(3)), on a case whose decision was delivered or that
     * is closed. Oldest delivery first; $decisionId narrows to one decision.
     *
     * @param  list<string>  $organizationIds
     * @return list<array{decision_id: string, case_number: ?string, employee_id: ?string, employee_name: ?string, is_unit_head: ?bool, penalty: DisciplinaryPenalty, penalty_amount: ?int, delivered_at: CarbonImmutable}>
     */
    public function finalDecisionsDelivered(array $organizationIds, CarbonInterface $from, CarbonInterface $to, ?string $decisionId = null): array;
}
