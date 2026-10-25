<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EstablishmentStatus;
use App\Services\Employees\EmploymentStatusTransition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The read-only checks of the Golden Path chain
 * organization -> unit -> position -> employee -> assignment -> card, in one
 * place for the `backbone:audit-integrity` command and the Core dashboard's
 * data-quality figure. Each check is one count; nothing is changed.
 */
final class BackboneIntegrityChecks
{
    /** @var array<string, string> check key => English description (the command's table) */
    public const LABELS = [
        'positions_missing_organization' => 'Positions naming an organization that does not exist',
        'positions_foreign_unit' => 'Positions whose unit belongs to another organization',
        'employees_bad_pointer' => 'Employees pointing at a missing, closed or foreign assignment',
        'assignments_unpointed' => 'Current assignments the employee does not point at',
        'replacements_unlinked' => 'Card replacement records not linked to the new card',
        'separated_still_placed' => 'Separated employees whose placement is still open',
        'separated_card_active' => 'Separated employees whose current ID card is still active',
        'employees_multiple_active_cards' => 'Employees holding more than one active ID card',
        'positions_over_capacity' => 'Positions held by more employees than their approved seats (one without an establishment)',
    ];

    /**
     * Which record each check points at, for the data-quality drill-down:
     * the column holding its id and the kind of record (position, employee, card).
     *
     * @var array<string, array{0: string, 1: string}>
     */
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
    ];

    /** @return array<string, int> check key => findings */
    public function counts(): array
    {
        return array_map(fn (Builder $query): int => (int) $query->count(), $this->queries());
    }

    /**
     * Up to $limit ids of the records a check found (Stage 8 data-quality
     * drill-down). Ids only: the person opening them still needs the record's
     * own permission and scope.
     *
     * @return list<string>
     */
    public function samples(string $key, int $limit = 20): array
    {
        $query = $this->queries()[$key] ?? null;
        if ($query === null) {
            return [];
        }
        [$column] = self::SUBJECTS[$key];

        return $query->select($column.' as subject_id')
            ->orderBy($column)
            ->limit(max(1, $limit))
            ->pluck('subject_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, Builder> */
    private function queries(): array
    {
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
            // Separations recorded before ADR 0002 left the placement open and the seat taken.
            'separated_still_placed' => DB::table('employees')
                ->whereIn('employees.status', array_map(fn ($status): string => $status->value, EmploymentStatusTransition::SEPARATED))
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'employees.current_assignment_id')
                    ->where('employee_assignments.assignment_status', AssignmentStatus::Active->value)),
            // Service eligibility already refuses these cards (employee_inactive); the card itself should be revoked.
            'separated_card_active' => DB::table('id_cards')
                ->join('employees', 'employees.id', '=', 'id_cards.employee_id')
                ->whereIn('employees.status', array_map(fn ($status): string => $status->value, EmploymentStatusTransition::SEPARATED))
                ->where('id_cards.status', CardStatus::Active->value)
                ->where('id_cards.is_current', true),
            // One trusted credential per employee: activation and replacement refuse a
            // second active card, so any finding is old data or a direct database edit.
            'employees_multiple_active_cards' => DB::table('employees')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('id_cards')
                    ->whereColumn('id_cards.employee_id', 'employees.id')
                    ->where('id_cards.status', CardStatus::Active->value)
                    ->groupBy('id_cards.employee_id')
                    ->havingRaw('count(*) > 1')),
            // The PositionCapacityService rule in SQL: approved seats in force today, or one.
            'positions_over_capacity' => DB::table('positions')
                ->whereRaw('('.$this->holders().') > (case when ('.$this->seats().') > 0 then ('.$this->seats().') else 1 end)', [
                    true, AssignmentStatus::Active->value,
                    EstablishmentStatus::Approved->value, $today = now()->toDateString(), $today,
                    EstablishmentStatus::Approved->value, $today, $today,
                ]),
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
