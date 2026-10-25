<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\IdCard;
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
