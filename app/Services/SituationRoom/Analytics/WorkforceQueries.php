<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\AssignmentStatus;
use App\Enums\EstablishmentStatus;
use Modules\Assessment\Contracts\Data\AssessmentCoverageValues;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationMemo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The workforce base queries, shared by the Workforce, Structure, Overview,
 * Critical Attention and Data Quality views so they cannot disagree.
 *
 * "Employed here" is the placement rule D-2 (PositionCapacityService): a
 * current, active assignment. A position holds its approved establishment
 * slots effective today, or one person when it has none. Every query is
 * confined to the scope before anything is counted, and nothing here ever
 * selects a whole employee row.
 *
 * An employee has at most one current assignment (unique partial index
 * employee_assignments_one_current_per_employee), so people are counted with
 * COUNT(*) over current assignments — the same number as COUNT(DISTINCT
 * employee_id), without the sort.
 */
final class WorkforceQueries
{
    public const AGE_BANDS = ['under_25', '25_34', '35_44', '45_54', '55_59', '60_plus', 'unknown'];

    /** Current, active assignments in scope, joined to their employee. */
    public static function assignments(SituationContext $context): Builder
    {
        $query = DB::table('employee_assignments as ea')
            ->join('employees as e', 'e.id', '=', 'ea.employee_id')
            ->where('ea.is_current', true)
            ->where('ea.assignment_status', AssignmentStatus::Active->value);

        $context->scope->apply($query, 'ea.organization_id');

        if (($unit = $context->filters->get('organization_unit_id')) !== null) {
            $query->where('ea.organization_unit_id', $unit);
        }

        return $query;
    }

    /**
     * Gender normalised to male / female / unknown with the vocabulary the
     * assessment coverage report already uses (English, letters, Amharic).
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function genderExpression(string $column = 'e.gender'): array
    {
        $male = AssessmentCoverageValues::GENDER_VALUES['male'];
        $female = AssessmentCoverageValues::GENDER_VALUES['female'];
        $in = fn (array $values): string => implode(', ', array_fill(0, count($values), '?'));

        return [
            "CASE WHEN LOWER(TRIM({$column})) IN ({$in($male)}) THEN 'male' WHEN LOWER(TRIM({$column})) IN ({$in($female)}) THEN 'female' ELSE 'unknown' END",
            [...$male, ...$female],
        ];
    }

    /**
     * Age band from date of birth, computed against today's date in SQL so
     * no birth date ever leaves the database.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function ageBandExpression(string $column = 'e.date_of_birth'): array
    {
        $today = CarbonImmutable::today();
        $born = fn (int $years): string => $today->subYears($years)->toDateString();

        return [
            "CASE WHEN {$column} IS NULL OR {$column} > ? OR {$column} < ? THEN 'unknown'"
            ." WHEN {$column} > ? THEN 'under_25' WHEN {$column} > ? THEN '25_34' WHEN {$column} > ? THEN '35_44'"
            ." WHEN {$column} > ? THEN '45_54' WHEN {$column} > ? THEN '55_59' ELSE '60_plus' END",
            // Under 15 or over 100 is not a plausible working age: counted as unknown (and as a data-quality issue).
            [$born(15), $born(100), $born(25), $born(35), $born(45), $born(55), $born(60)],
        ];
    }

    /**
     * Capacity, occupancy and vacancy per organization of the positions in
     * scope, by rule D-2. Over-capacity occupants are counted apart and never
     * cancel out a vacancy elsewhere.
     *
     * @return array<string, array{positions: int, capacity: int, occupied: int, vacant: int, over: int}>
     */
    public static function capacityByOrganization(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'capacity'), fn (): array => self::computeCapacity($context));
    }

    /** @return array<string, array{positions: int, capacity: int, occupied: int, vacant: int, over: int}> */
    private static function computeCapacity(SituationContext $context): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $establishment = DB::table('position_establishments')
            ->selectRaw('position_id, SUM(approved_slots) AS slots')
            ->where('status', EstablishmentStatus::Approved->value)
            ->whereNull('deleted_at')
            ->whereRaw('DATE(effective_from) <= ?', [$today])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereRaw('DATE(effective_to) >= ?', [$today]))
            ->groupBy('position_id');

        $occupants = DB::table('employee_assignments')
            ->selectRaw('position_id, COUNT(*) AS n')
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->whereNotNull('position_id')
            ->groupBy('position_id');

        $capacity = 'CASE WHEN COALESCE(est.slots, 0) > 0 THEN est.slots ELSE 1 END';
        $held = 'COALESCE(occ.n, 0)';

        $query = DB::table('positions as p')
            ->leftJoinSub($establishment, 'est', 'est.position_id', '=', 'p.id')
            ->leftJoinSub($occupants, 'occ', 'occ.position_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->selectRaw('p.organization_id AS organization_id, COUNT(*) AS positions')
            ->selectRaw("SUM({$capacity}) AS capacity")
            ->selectRaw("SUM(CASE WHEN {$held} < {$capacity} THEN {$held} ELSE {$capacity} END) AS occupied")
            ->selectRaw("SUM(CASE WHEN {$held} < {$capacity} THEN {$capacity} - {$held} ELSE 0 END) AS vacant")
            ->selectRaw("SUM(CASE WHEN {$held} > {$capacity} THEN {$held} - {$capacity} ELSE 0 END) AS over_capacity")
            ->groupBy('p.organization_id');

        $context->scope->apply($query, 'p.organization_id');

        if (($unit = $context->filters->get('organization_unit_id')) !== null) {
            $query->where('p.organization_unit_id', $unit);
        }

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[(string) $row->organization_id] = [
                'positions' => (int) $row->positions,
                'capacity' => (int) $row->capacity,
                'occupied' => (int) $row->occupied,
                'vacant' => (int) $row->vacant,
                'over' => (int) $row->over_capacity,
            ];
        }

        return $rows;
    }

    /** @return array<string, int> distinct employees per organization of their current assignment */
    public static function headcountByOrganization(SituationContext $context): array
    {
        // No join to employees: a plain count needs only the assignment (the foreign key guarantees the person).
        $assignments = DB::table('employee_assignments as ea')
            ->where('ea.is_current', true)
            ->where('ea.assignment_status', AssignmentStatus::Active->value);
        $context->scope->apply($assignments, 'ea.organization_id');
        if (($unit = $context->filters->get('organization_unit_id')) !== null) {
            $assignments->where('ea.organization_unit_id', $unit);
        }

        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'headcount_by_organization'), fn (): array => $assignments
            ->selectRaw('ea.organization_id AS organization_id, COUNT(*) AS total')
            ->groupBy('ea.organization_id')
            ->pluck('total', 'organization_id')
            ->mapWithKeys(fn ($total, $id): array => [(string) $id => (int) $total])
            ->all());
    }

    public static function headcount(SituationContext $context): int
    {
        return array_sum(self::headcountByOrganization($context));
    }

    /**
     * People per value of an expression over current assignments in scope.
     *
     * @param  list<string>  $bindings
     * @return array<string, int>
     */
    public static function countBy(SituationContext $context, string $name, string $expression, array $bindings = []): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'count_by:'.$name), fn (): array => self::assignments($context)
            ->selectRaw("{$expression} AS bucket, COUNT(*) AS total", $bindings)
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->mapWithKeys(fn ($total, $bucket): array => [(string) $bucket => (int) $total])
            ->all());
    }
}
