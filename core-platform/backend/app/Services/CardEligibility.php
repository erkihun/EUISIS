<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\User;
use App\Support\EligibilityRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one card eligibility specification (ADR 0009). Every rule is an
 * EligibilityRule, so the same SQL decides one employee (CardRequests,
 * the API's card-eligibility) and filters the card request picker in one
 * bounded query, whatever the number of employees:
 *  - the employee: approved, active, a complete name, no confirmed duplicate;
 *  - the placement: current, active and in force, in an organization in
 *    service, on a position in use;
 *  - the evidence a card needs (EmployeeEvidence, id_cards settings);
 *  - no request waiting for a decision;
 *  - the request type the current card allows: a new card when there is none,
 *    renewal or correction of an active or issued card, a replacement of a
 *    lost, damaged, revoked, expired or suspended one; nothing while a card
 *    is being produced.
 */
final readonly class CardEligibility
{
    /** Card statuses that leave the employee without a card, so a new one may be requested. */
    private const TYPES_BY_CURRENT_CARD = [
        IdCard::ACTIVE => ['renewal', 'correction'],
        IdCard::ISSUED => ['renewal', 'correction'],
        IdCard::LOST => ['lost'],
        IdCard::DAMAGED => ['damaged'],
        IdCard::REVOKED => ['replacement'],
        IdCard::EXPIRED => ['replacement'],
        IdCard::SUSPENDED => ['replacement'],
        IdCard::PENDING_PRINT => [],
        IdCard::PRINTED => [],
    ];

    public function __construct(private EmployeeEvidence $evidence, private OrganizationScope $scope) {}

    /**
     * Why this employee cannot have a card request of this type now, as code =>
     * message; empty when eligible. At approval the waiting request itself and
     * the type (checked by the approval) are left out.
     *
     * @return array<string, string>
     */
    public function decide(Employee $employee, ?string $type = 'new', bool $forApproval = false): array
    {
        $rules = $this->rules($forApproval ? null : $type, $forApproval);
        $row = EligibilityRule::selectFlags(Employee::query()->whereKey($employee->getKey())->select('employees.id'), $rules)->toBase()->first();

        return $row === null ? ['EMPLOYEE_NOT_FOUND' => 'No such employee.'] : EligibilityRule::failures($row, $rules);
    }

    /**
     * The request types the employee's current card allows (one rule for the
     * picker and for submission).
     *
     * @return list<string>
     */
    public static function typesFor(?string $currentCardStatus): array
    {
        return $currentCardStatus === null || ! array_key_exists($currentCardStatus, self::TYPES_BY_CURRENT_CARD)
            ? ['new']
            : self::TYPES_BY_CURRENT_CARD[$currentCardStatus];
    }

    /**
     * The card request picker: employees in the reader's scope who may have a
     * request of some type now, with the types and their organization, in one
     * query (plus the cached scope lookup).
     *
     * @return Collection<int, array{id: string, employee_number: string, full_name: string, status: string, current_assignment_id: ?string, organization: ?array<string, mixed>, eligible_request_types: list<string>}>
     */
    public function eligibleEmployees(User $reader, string $term = '', int $limit = 200): Collection
    {
        $query = EligibilityRule::passing($this->candidates($reader, $term), $this->rules(null, false));

        return $query->limit($limit)->toBase()->get()->map(fn (object $row): array => $this->pickerRow($row))
            ->filter(fn (array $row): bool => $row['eligible_request_types'] !== [])->values();
    }

    /**
     * The employees of the reader's scope who may NOT have a card now, with
     * every failing rule's code: the picker's diagnostic view, one query too.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ineligibleEmployees(User $reader, string $term = '', int $limit = 200): Collection
    {
        $rules = $this->rules(null, false);
        $failing = implode(' or ', array_map(fn (EligibilityRule $rule): string => "({$rule->failsWhen})", $rules));
        $bindings = array_merge(...array_map(fn (EligibilityRule $rule): array => $rule->bindings, $rules));
        $query = EligibilityRule::selectFlags($this->candidates($reader, $term), $rules)->whereRaw("({$failing})", $bindings);

        return $query->limit($limit)->toBase()->get()->map(fn (object $row): array => $this->pickerRow($row) + [
            'reasons' => array_keys(EligibilityRule::failures($row, $rules)),
        ]);
    }

    /**
     * The rules, written once.
     *
     * @return list<EligibilityRule>
     */
    public function rules(?string $type, bool $forApproval = false): array
    {
        $true = EligibilityRule::true();
        $today = today()->toDateString();
        $preApproval = "'".implode("', '", Employee::PRE_APPROVAL)."'";
        $rules = [
            new EligibilityRule('EMPLOYEE_NOT_APPROVED', "employees.status in ({$preApproval})", [],
                'The employee has not been approved yet: a card follows verification and approval.'),
            new EligibilityRule('EMPLOYEE_NOT_ACTIVE', "employees.status not in ({$preApproval}, '".Employee::ACTIVE."')", [],
                'Cards are requested only for active employees.'),
            new EligibilityRule('NO_ACTIVE_ASSIGNMENT', 'not exists (select 1 from employee_assignments ca where ca.id = employees.current_assignment_id'
                ." and ca.employee_id = employees.id and ca.is_current = {$true} and ca.assignment_status = 'active'"
                .' and (ca.effective_from is null or ca.effective_from <= ?) and (ca.effective_to is null or ca.effective_to >= ?))', [$today, $today],
                'The employee has no placement in force.'),
            new EligibilityRule('ORGANIZATION_INACTIVE', 'exists (select 1 from employee_assignments oa join organizations o on o.id = oa.organization_id'
                ." where oa.id = employees.current_assignment_id and o.status <> 'active')", [],
                'The employee\'s organization is not in service.'),
            new EligibilityRule('POSITION_INACTIVE', 'exists (select 1 from employee_assignments pa left join positions p on p.id = pa.position_id'
                ." where pa.id = employees.current_assignment_id and (p.id is null or p.is_active <> {$true} or p.deleted_at is not null))", [],
                'The employee\'s position is retired.'),
            new EligibilityRule('IDENTITY_INCOMPLETE', "(trim(coalesce(employees.first_name, '')) = '' or trim(coalesce(employees.last_name, '')) = '')", [],
                'The employee\'s first and last names are required on a card.'),
            new EligibilityRule('DUPLICATE_CONFIRMED', "exists (select 1 from employee_duplicate_reviews dr where dr.employee_id = employees.id and dr.decision = 'confirmed_duplicate')", [],
                'This record is a confirmed duplicate of another employee; it waits for a controlled merge.'),
            ...$this->evidence->rules($this->evidence->forCards()),
        ];
        if (! $forApproval) {
            $pending = "'".implode("', '", CardRequest::PENDING)."'";
            $rules[] = new EligibilityRule('PENDING_CARD_REQUEST_EXISTS', "exists (select 1 from card_requests pr where pr.employee_id = employees.id and pr.status in ({$pending}))", [],
                'This employee already has a card request waiting for a decision.');
        }
        if ($type === null && ! $forApproval) {
            $rules[] = $this->currentCardRule('CARD_IN_PRODUCTION', [IdCard::PENDING_PRINT, IdCard::PRINTED], true,
                'A card for this employee is being produced.');
        } elseif ($type === 'new') {
            $live = "'".implode("', '", IdCard::LIVE)."'";
            $rules[] = new EligibilityRule('ACTIVE_CARD_EXISTS', "exists (select 1 from id_cards lc where lc.employee_id = employees.id and lc.status in ({$live}))", [],
                'The employee already has a live card; request a correction, or replace it when lost or damaged.');
            $rules[] = $this->currentCardRule('REPLACEMENT_REQUIRED', IdCard::REPLACEABLE, true,
                'The employee\'s card is lost, damaged, revoked, expired or suspended: request its replacement instead.');
        } elseif (in_array($type, ['renewal', 'correction'], true)) {
            $rules[] = $this->currentCardRule('NO_CURRENT_CARD', [IdCard::ACTIVE, IdCard::ISSUED], false,
                'Renewal and correction require an issued or active current card.');
        } elseif (in_array($type, ['lost', 'damaged', 'replacement'], true)) {
            $statuses = array_keys(array_filter(self::TYPES_BY_CURRENT_CARD, fn (array $types): bool => in_array($type, $types, true)));
            $rules[] = $this->currentCardRule('REQUEST_TYPE_NOT_ALLOWED', $statuses, false,
                'The employee\'s current card does not allow this kind of request.');
        }

        return $rules;
    }

    /** Employees in the reader's scope, searched, with what the picker shows. */
    private function candidates(User $reader, string $term): Builder
    {
        $organizations = $this->scope->organizationIds($reader);
        $true = EligibilityRule::true();

        return Employee::query()
            ->leftJoin('employee_assignments as placement', 'placement.id', '=', 'employees.current_assignment_id')
            ->leftJoin('organizations as organization', 'organization.id', '=', 'placement.organization_id')
            ->when($organizations !== null, fn (Builder $q) => $q->whereIn('placement.organization_id', $organizations))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->where('employees.employee_number', 'like', $term.'%')
                ->orWhere('employees.full_name', 'like', "%{$term}%")->orWhere('employees.name_en', 'like', "%{$term}%")))
            ->select(['employees.id', 'employees.employee_number', 'employees.full_name', 'employees.status', 'employees.current_assignment_id',
                'organization.id as organization_id', 'organization.name_en as organization_name_en', 'organization.name_am as organization_name_am'])
            ->selectSub(fn ($q) => $q->from('id_cards as cc')->whereColumn('cc.employee_id', 'employees.id')->whereRaw("cc.is_current = {$true}")
                ->orderByDesc('cc.created_at')->limit(1)->select('cc.status'), 'current_card_status')
            ->orderBy('employees.full_name')->orderBy('employees.id');
    }

    /** @return array<string, mixed> */
    private function pickerRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'employee_number' => (string) $row->employee_number,
            'full_name' => (string) $row->full_name,
            'status' => (string) $row->status,
            'current_assignment_id' => $row->current_assignment_id,
            'organization' => $row->organization_id === null ? null
                : ['id' => (string) $row->organization_id, 'name_en' => $row->organization_name_en, 'name_am' => $row->organization_name_am],
            'eligible_request_types' => self::typesFor($row->current_card_status),
        ];
    }

    /**
     * A rule on the employee's current card: with $failsIfPresent it fails when such
     * a card exists; otherwise it fails when none does.
     *
     * @param  list<string>  $statuses
     */
    private function currentCardRule(string $code, array $statuses, bool $failsIfPresent, string $message): EligibilityRule
    {
        $true = EligibilityRule::true();
        $list = "'".implode("', '", $statuses)."'";
        $exists = "exists (select 1 from id_cards cur where cur.employee_id = employees.id and cur.is_current = {$true} and cur.status in ({$list}))";

        return new EligibilityRule($code, $failsIfPresent ? $exists : "not {$exists}", [], $message);
    }
}
