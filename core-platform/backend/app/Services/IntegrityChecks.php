<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\OrganizationEdge;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Golden Path integrity checks (EUISIS App\Services\Core\BackboneIntegrityChecks,
 * the Stage 8 data-quality page): each counts records that break a link in the
 * chain from organization to card, and can list the first ones found.
 */
final class IntegrityChecks
{
    /** check => [subject id column, subject kind] */
    public const SUBJECTS = [
        'positions_missing_organization' => ['positions.id', 'position'],
        'positions_foreign_unit' => ['positions.id', 'position'],
        'employees_bad_pointer' => ['employees.id', 'employee'],
        'assignments_unpointed' => ['employee_assignments.employee_id', 'employee'],
        'replacements_unlinked' => ['card_replacements.old_card_id', 'card'],
        'separated_still_placed' => ['employees.id', 'employee'],
        'separated_card_active' => ['id_cards.id', 'card'],
        'employees_multiple_active_cards' => ['employees.id', 'employee'],
        'positions_over_capacity' => ['positions.id', 'position'],
        'placed_in_inactive_position' => ['employee_assignments.employee_id', 'employee'],
        'placed_in_ended_organization' => ['employee_assignments.employee_id', 'employee'],
        'active_without_placement' => ['employees.id', 'employee'],
        'overlapping_assignments' => ['a.employee_id', 'employee'],
        'possible_duplicate_employees' => ['employees.id', 'employee'],
        'expired_card_still_active' => ['id_cards.id', 'card'],
        'active_without_card' => ['employees.id', 'employee'],
        // P4/P5 (ADR 0011)
        'active_card_inactive_employee' => ['id_cards.id', 'card'],
        'active_card_revoked_credential' => ['id_cards.id', 'card'],
        'active_without_photo' => ['employees.id', 'employee'],
        'active_missing_required_document' => ['employees.id', 'employee'],
        'occupancy_without_assignment' => ['position_occupancies.position_id', 'position'],
        'hierarchy_multiple_parents' => ['organizations.id', 'organization'],
        'hierarchy_edge_ended_organization' => ['organizations.id', 'organization'],
    ];

    /**
     * The severity of each check's findings (ADR 0011), fixed so the same
     * finding always weighs the same: a broken identity or credential chain
     * is critical; a placement or structure that breaks a rule is high; what
     * blocks a card or needs a decision is medium; what is merely missing is low.
     */
    public const SEVERITY = [
        'employees_bad_pointer' => 'critical', 'assignments_unpointed' => 'critical', 'overlapping_assignments' => 'critical',
        'separated_card_active' => 'critical', 'employees_multiple_active_cards' => 'critical', 'active_card_inactive_employee' => 'critical',
        'active_card_revoked_credential' => 'critical',
        'positions_missing_organization' => 'high', 'positions_foreign_unit' => 'high', 'replacements_unlinked' => 'high',
        'separated_still_placed' => 'high', 'positions_over_capacity' => 'high', 'placed_in_inactive_position' => 'high',
        'placed_in_ended_organization' => 'high', 'expired_card_still_active' => 'high', 'occupancy_without_assignment' => 'high',
        'hierarchy_multiple_parents' => 'high', 'hierarchy_edge_ended_organization' => 'high',
        'active_without_placement' => 'medium', 'possible_duplicate_employees' => 'medium', 'active_without_photo' => 'medium',
        'active_missing_required_document' => 'low', 'active_without_card' => 'low',
    ];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    /** Short English summaries for issue rows, exports and e-mail; the screens show the translated check name. */
    public const SUMMARIES = [
        'positions_missing_organization' => 'The position names an organization that does not exist.',
        'positions_foreign_unit' => 'The position\'s unit belongs to another organization.',
        'employees_bad_pointer' => 'The employee points at a missing, closed or foreign placement.',
        'assignments_unpointed' => 'A current placement the employee does not point at.',
        'replacements_unlinked' => 'A card replacement record is not linked to the new card.',
        'separated_still_placed' => 'A separated employee still has an open placement.',
        'separated_card_active' => 'A separated employee\'s card is still active.',
        'employees_multiple_active_cards' => 'The employee holds more than one active card.',
        'positions_over_capacity' => 'More people hold the position than its seats.',
        'placed_in_inactive_position' => 'The employee is placed in a position that is not approved (draft, suspended, abolished or archived).',
        'placed_in_ended_organization' => 'The employee is placed in an organization that is no longer active.',
        'active_without_placement' => 'An active employee has no active placement.',
        'overlapping_assignments' => 'Two placements of the employee overlap in time.',
        'possible_duplicate_employees' => 'Another employee has the same name and date of birth, with no review decision.',
        'expired_card_still_active' => 'The card is past its expiry date but still active or issued.',
        'active_without_card' => 'A placed, active employee has no live card.',
        'active_card_inactive_employee' => 'The card is active but its holder is not an active employee.',
        'active_card_revoked_credential' => 'The card is active but its credential has been revoked or replaced.',
        'active_without_photo' => 'An active employee has no verified current photo, which the settings require.',
        'active_missing_required_document' => 'An active employee lacks a verified, unexpired document the settings require.',
        'occupancy_without_assignment' => 'An occupancy record names a placement that is not current and active.',
        'hierarchy_multiple_parents' => 'The organization has more than one structural parent in the hierarchy in force.',
        'hierarchy_edge_ended_organization' => 'An organization that is no longer active is still in the hierarchy in force.',
    ];

    public function __construct(private readonly CoreSettings $settings) {}

    /** @return array<string, int> */
    public function counts(): array
    {
        return array_map(fn (Builder $query): int => (int) $query->count(), $this->queries());
    }

    /** @return list<string> */
    public function samples(string $key, int $limit = 20): array
    {
        $query = $this->queries()[$key] ?? null;
        if ($query === null) {
            return [];
        }
        [$column] = self::SUBJECTS[$key];

        return $query->select($column.' as subject_id')->orderBy($column)->limit(max(1, $limit))
            ->pluck('subject_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }

    /**
     * Every finding's subject id (the scanner's input), up to a bound.
     *
     * @return list<string>
     */
    public function subjects(string $key, int $limit): array
    {
        $query = $this->queries()[$key] ?? null;
        if ($query === null) {
            return [];
        }
        [$column] = self::SUBJECTS[$key];

        return $query->select($column.' as subject_id')->distinct()->orderBy('subject_id')->limit(max(1, $limit))
            ->pluck('subject_id')->map(fn ($id): string => (string) $id)->values()->all();
    }

    /** @return array<string, Builder> */
    private function queries(): array
    {
        $today = now()->toDateString();

        return [
            'positions_missing_organization' => DB::table('positions')
                ->whereNotNull('organization_id')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('organizations')->whereColumn('organizations.id', 'positions.organization_id')),
            'positions_foreign_unit' => DB::table('positions')
                ->join('organization_units', 'organization_units.id', '=', 'positions.organization_unit_id')
                ->whereNotNull('positions.organization_id')
                ->whereColumn('organization_units.organization_id', '!=', 'positions.organization_id'),
            'employees_bad_pointer' => DB::table('employees')
                ->whereNotNull('employees.current_assignment_id')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'employees.current_assignment_id')
                    ->whereColumn('employee_assignments.employee_id', 'employees.id')
                    ->where('employee_assignments.is_current', true)),
            'assignments_unpointed' => DB::table('employee_assignments')
                ->join('employees', 'employees.id', '=', 'employee_assignments.employee_id')
                ->where('employee_assignments.is_current', true)
                ->where(fn (Builder $q) => $q->whereNull('employees.current_assignment_id')
                    ->orWhereColumn('employees.current_assignment_id', '!=', 'employee_assignments.id')),
            'replacements_unlinked' => DB::table('card_replacements')
                ->whereColumn('new_card_id', 'old_card_id')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('id_cards')->whereColumn('id_cards.previous_card_id', 'card_replacements.old_card_id')),
            'separated_still_placed' => DB::table('employees')
                ->whereIn('employees.status', Employee::SEPARATED)
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'employees.current_assignment_id')
                    ->where('employee_assignments.assignment_status', 'active')),
            'separated_card_active' => DB::table('id_cards')
                ->join('employees', 'employees.id', '=', 'id_cards.employee_id')
                ->whereIn('employees.status', Employee::SEPARATED)
                ->where('id_cards.status', IdCard::ACTIVE)
                ->where('id_cards.is_current', true),
            'employees_multiple_active_cards' => DB::table('employees')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('id_cards')
                    ->whereColumn('id_cards.employee_id', 'employees.id')
                    ->where('id_cards.status', IdCard::ACTIVE)
                    ->groupBy('id_cards.employee_id')
                    ->havingRaw('count(*) > 1')),
            // The SeatCapacity rule in SQL: approved seats in force today, else the Workforce setting.
            'positions_over_capacity' => DB::table('positions')
                ->whereRaw('('.$this->holders().') > (case when ('.$this->seats().') > 0 then ('.$this->seats().') else ? end)', [
                    true, 'active',
                    'approved', $today, $today,
                    'approved', $today, $today,
                    $this->settings->int('workforce.default_seat_capacity'),
                ]),
            'placed_in_inactive_position' => DB::table('employee_assignments')
                ->join('positions', 'positions.id', '=', 'employee_assignments.position_id')
                ->where('employee_assignments.is_current', true)->where('employee_assignments.assignment_status', 'active')
                ->where(fn (Builder $q) => $q->where('positions.is_active', false)->orWhereNotNull('positions.deleted_at')),
            'placed_in_ended_organization' => DB::table('employee_assignments')
                ->join('organizations', 'organizations.id', '=', 'employee_assignments.organization_id')
                ->where('employee_assignments.is_current', true)->where('employee_assignments.assignment_status', 'active')
                ->where(fn (Builder $q) => $q->whereIn('organizations.status', OrganizationUsage::ENDED)->orWhereNotNull('organizations.deleted_at')),
            'active_without_placement' => DB::table('employees')
                ->where('employees.status', Employee::ACTIVE)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'employees.current_assignment_id')
                    ->where('employee_assignments.assignment_status', 'active')),
            // Two placements of one employee whose periods overlap (a transfer closes the old one the day before).
            'overlapping_assignments' => DB::table('employee_assignments as a')
                ->join('employee_assignments as b', fn ($join) => $join->on('b.employee_id', '=', 'a.employee_id')->whereColumn('b.id', '>', 'a.id'))
                ->whereRaw("a.effective_from <= coalesce(b.effective_to, '9999-12-31')")
                ->whereRaw("b.effective_from <= coalesce(a.effective_to, '9999-12-31')"),
            // Same name and date of birth as another employee, with no reviewer decision about the pair.
            'possible_duplicate_employees' => DB::table('employees')
                ->whereNotNull('employees.date_of_birth')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('employees as other')
                    ->whereColumn('other.id', '!=', 'employees.id')
                    ->whereColumn('other.date_of_birth', 'employees.date_of_birth')
                    ->whereRaw('lower(other.full_name) = lower(employees.full_name)')
                    ->whereNotExists(fn (Builder $r) => $r->selectRaw('1')->from('employee_duplicate_reviews')
                        ->where(fn (Builder $pair) => $pair->where(fn (Builder $x) => $x->whereColumn('employee_duplicate_reviews.employee_id', 'employees.id')->whereColumn('employee_duplicate_reviews.matched_employee_id', 'other.id'))
                            ->orWhere(fn (Builder $x) => $x->whereColumn('employee_duplicate_reviews.employee_id', 'other.id')->whereColumn('employee_duplicate_reviews.matched_employee_id', 'employees.id'))))),
            'expired_card_still_active' => DB::table('id_cards')
                ->whereIn('id_cards.status', [IdCard::ACTIVE, IdCard::ISSUED])
                ->whereNotNull('id_cards.expires_at')->where('id_cards.expires_at', '<=', now()),
            ...$this->oversightQueries(),
            'active_without_card' => DB::table('employees')
                ->where('employees.status', Employee::ACTIVE)
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'employees.current_assignment_id')
                    ->where('employee_assignments.assignment_status', 'active'))
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('id_cards')
                    ->whereColumn('id_cards.employee_id', 'employees.id')
                    ->whereIn('id_cards.status', IdCard::LIVE)),
        ];
    }

    /** @return array<string, Builder> the P4/P5 checks (ADR 0011) */
    private function oversightQueries(): array
    {
        $none = fn (string $table): Builder => DB::table($table)->whereRaw('1 = 0');
        $photoRequired = (bool) $this->settings->get('id_cards.require_photo') || (bool) $this->settings->get('workforce.verification_requires_photo');
        $documents = array_values(array_filter((array) $this->settings->get('workforce.required_document_types')));
        $version = HierarchyVersion::current();
        $edges = fn (Builder $q, string $alias = 'organization_edges') => $q->where($alias.'.hierarchy_version_id', $version?->id)
            ->where($alias.'.relationship_type', OrganizationEdge::STRUCTURAL);

        return [
            'active_card_inactive_employee' => DB::table('id_cards')
                ->join('employees', 'employees.id', '=', 'id_cards.employee_id')
                ->where('id_cards.status', IdCard::ACTIVE)->where('id_cards.is_current', true)
                ->whereNotIn('employees.status', [Employee::ACTIVE, ...Employee::SEPARATED]),
            'active_card_revoked_credential' => DB::table('id_cards')
                ->where('id_cards.status', IdCard::ACTIVE)->where('id_cards.is_current', true)
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('digital_credentials')->whereColumn('digital_credentials.id_card_id', 'id_cards.id'))
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('digital_credentials')->whereColumn('digital_credentials.id_card_id', 'id_cards.id')
                    ->where('digital_credentials.status', 'active')),
            'active_without_photo' => ! $photoRequired ? $none('employees') : DB::table('employees')
                ->where('employees.status', Employee::ACTIVE)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_documents')->whereColumn('employee_documents.employee_id', 'employees.id')
                    ->where('employee_documents.kind', 'photo')->where('employee_documents.is_current', true)->where('employee_documents.status', 'verified')),
            'active_missing_required_document' => $documents === [] ? $none('employees') : DB::table('employees')
                ->where('employees.status', Employee::ACTIVE)
                ->where(function (Builder $any) use ($documents): void {
                    foreach ($documents as $type) {
                        $any->orWhereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_documents')->whereColumn('employee_documents.employee_id', 'employees.id')
                            ->where('employee_documents.kind', 'document')->where('employee_documents.document_type', $type)
                            ->where('employee_documents.is_current', true)->where('employee_documents.status', 'verified')
                            ->where(fn (Builder $e) => $e->whereNull('employee_documents.expires_at')->orWhere('employee_documents.expires_at', '>', now()->toDateString())));
                    }
                }),
            'occupancy_without_assignment' => DB::table('position_occupancies')
                ->where('position_occupancies.status', 'active')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'position_occupancies.employee_assignment_id')
                    ->whereColumn('employee_assignments.position_id', 'position_occupancies.position_id')
                    ->where('employee_assignments.is_current', true)->where('employee_assignments.assignment_status', 'active')),
            'hierarchy_multiple_parents' => $version === null ? $none('organizations') : DB::table('organizations')
                ->whereExists(fn (Builder $q) => $edges($q->selectRaw('1')->from('organization_edges as e1')
                    ->join('organization_edges as e2', fn ($join) => $join->on('e2.child_organization_id', '=', 'e1.child_organization_id')->whereColumn('e2.id', '>', 'e1.id')
                        ->where('e2.hierarchy_version_id', $version->id)->where('e2.relationship_type', OrganizationEdge::STRUCTURAL))
                    ->whereColumn('e1.child_organization_id', 'organizations.id'), 'e1')),
            'hierarchy_edge_ended_organization' => $version === null ? $none('organizations') : DB::table('organizations')
                ->where(fn (Builder $q) => $q->whereIn('organizations.status', OrganizationUsage::ENDED)->orWhereNotNull('organizations.deleted_at'))
                ->whereExists(fn (Builder $q) => $edges($q->selectRaw('1')->from('organization_edges'))
                    ->where(fn (Builder $e) => $e->whereColumn('organization_edges.child_organization_id', 'organizations.id')->orWhereColumn('organization_edges.parent_organization_id', 'organizations.id'))),
        ];
    }

    private function holders(): string
    {
        return 'select count(*) from employee_assignments where employee_assignments.position_id = positions.id'
            .' and employee_assignments.is_current = ? and employee_assignments.assignment_status = ?';
    }

    private function seats(): string
    {
        return 'select coalesce(sum(approved_slots), 0) from position_establishments where position_establishments.position_id = positions.id'
            .' and position_establishments.status = ? and position_establishments.deleted_at is null'
            .' and position_establishments.effective_from <= ?'
            .' and (position_establishments.effective_to is null or position_establishments.effective_to >= ?)';
    }
}
