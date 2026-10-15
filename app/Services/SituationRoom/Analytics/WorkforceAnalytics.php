<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\EmploymentType;
use App\Models\Occupation;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;

/**
 * Workforce Composition & Staffing. Counts people by their current, active
 * assignment; capacity and vacancy by rule D-2. Demographics are aggregates
 * computed in SQL: no birth date, gender or name of an individual leaves the
 * database. Education and length of service are not recorded per employee,
 * so they are reported as NEEDS_DATA rather than estimated.
 */
final class WorkforceAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.assignments';

    public function panels(SituationContext $context): array
    {
        return [
            'workforce_kpis' => fn (): SituationPanel => $this->kpis($context),
            'headcount_by_organization' => fn (): SituationPanel => $this->headcountByOrganization($context),
            'capacity_by_organization' => fn (): SituationPanel => $this->capacityByOrganization($context),
            'gender' => fn (): SituationPanel => $this->gender($context),
            'age_bands' => fn (): SituationPanel => $this->ageBands($context),
            'gender_by_organization' => fn (): SituationPanel => $this->genderByOrganization($context),
            'employment_type' => fn (): SituationPanel => $this->employmentType($context),
            'employee_status' => fn (): SituationPanel => $this->employeeStatus($context),
            'occupations' => fn (): SituationPanel => $this->occupations($context),
            'grade_levels' => fn (): SituationPanel => $this->gradeLevels($context),
            'education' => fn (): SituationPanel => $this->notice('education', 'situationRoom.sources.employees', 'NEEDS_DATA'),
            'tenure' => fn (): SituationPanel => $this->notice('tenure', 'situationRoom.sources.employees', 'NEEDS_DATA'),
        ];
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $headcount = WorkforceQueries::headcount($context);
        $capacity = array_values(WorkforceQueries::capacityByOrganization($context));
        $sum = fn (string $key): int => array_sum(array_column($capacity, $key));
        [$gender, $bindings] = WorkforceQueries::genderExpression();
        $byGender = WorkforceQueries::countBy($context, 'gender', $gender, $bindings);
        $female = (int) ($byGender['female'] ?? 0);
        $known = $female + (int) ($byGender['male'] ?? 0);

        return SituationPanel::kpis('workforce_kpis', $this->title('workforce_kpis'), self::SOURCE, [
            $this->kpi('headcount', $headcount),
            $this->kpi('capacity', $sum('capacity')),
            $this->kpi('occupied', $sum('occupied')),
            $this->kpi('vacant', $sum('vacant')),
            $this->kpi('vacancy_rate', $this->percent($sum('vacant'), $sum('capacity')), ['unit' => '%']),
            $this->kpi('female_share', $this->percent($female, $known), ['unit' => '%']),
            $this->kpi('over_capacity', $sum('over')),
        ]);
    }

    private function headcountByOrganization(SituationContext $context): SituationPanel
    {
        $rolled = $this->rolledUp($context, WorkforceQueries::headcountByOrganization($context));

        return $this->chart('headcount_by_organization', 'hbar', self::SOURCE, $rolled['categories'], [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => $rolled['values']],
        ], span: 2);
    }

    private function capacityByOrganization(SituationContext $context): SituationPanel
    {
        $rows = WorkforceQueries::capacityByOrganization($context);
        $matrix = $this->rolledUpMatrix($context, array_map(fn (array $r): array => ['occupied' => $r['occupied'], 'vacant' => $r['vacant']], $rows), ['occupied', 'vacant'], 'capacity');

        return $this->chart('capacity_by_organization', 'stacked', 'situationRoom.sources.establishment', $matrix['categories'], $matrix['series'], span: 2);
    }

    private function gender(SituationContext $context): SituationPanel
    {
        [$expression, $bindings] = WorkforceQueries::genderExpression();
        $counts = WorkforceQueries::countBy($context, 'gender', $expression, $bindings);
        $buckets = ['male', 'female', 'unknown'];

        return $this->chart('gender', 'donut', self::SOURCE, $this->valueLabels('gender', $buckets), [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function ageBands(SituationContext $context): SituationPanel
    {
        [$expression, $bindings] = WorkforceQueries::ageBandExpression();
        $counts = WorkforceQueries::countBy($context, 'age', $expression, $bindings);

        return $this->chart('age_bands', 'bar', self::SOURCE, $this->valueLabels('age', WorkforceQueries::AGE_BANDS), [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, WorkforceQueries::AGE_BANDS)],
        ]);
    }

    private function genderByOrganization(SituationContext $context): SituationPanel
    {
        [$expression, $bindings] = WorkforceQueries::genderExpression();
        $rows = WorkforceQueries::assignments($context)
            ->selectRaw("ea.organization_id AS organization_id, {$expression} AS bucket, COUNT(*) AS total", $bindings)
            ->groupBy('ea.organization_id')
            ->groupBy('bucket')
            ->get();
        $matrix = [];
        foreach ($rows as $row) {
            $matrix[(string) $row->organization_id][(string) $row->bucket] = (int) $row->total;
        }
        $result = $this->rolledUpMatrix($context, $matrix, ['male', 'female', 'unknown'], 'gender');

        return $this->chart('gender_by_organization', 'stacked', self::SOURCE, $result['categories'], $result['series'], span: 2);
    }

    private function employmentType(SituationContext $context): SituationPanel
    {
        $counts = WorkforceQueries::countBy($context, 'employment_type', "COALESCE(NULLIF(e.employment_type, ''), 'unknown')");
        $buckets = [...EmploymentType::values(), 'unknown'];
        $other = array_sum(array_diff_key($counts, array_flip($buckets)));
        $values = array_map(fn (string $b): int => ($counts[$b] ?? 0) + ($b === 'unknown' ? $other : 0), $buckets);

        return $this->chart('employment_type', 'donut', self::SOURCE, $this->valueLabels('employment_type', $buckets), [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => $values],
        ]);
    }

    private function employeeStatus(SituationContext $context): SituationPanel
    {
        $counts = WorkforceQueries::countBy($context, 'employee_status', "COALESCE(e.status, 'unknown')");
        ksort($counts);

        return $this->chart('employee_status', 'bar', self::SOURCE, $this->valueLabels('employee_status', array_keys($counts)), [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => array_values($counts)],
        ]);
    }

    private function occupations(SituationContext $context): SituationPanel
    {
        $counts = WorkforceQueries::assignments($context)
            ->leftJoin('positions as p', 'p.id', '=', 'ea.position_id')
            ->selectRaw("COALESCE(CAST(p.occupation_id AS VARCHAR(64)), '__none') AS bucket, COUNT(*) AS total")
            ->groupByRaw('p.occupation_id')
            ->pluck('total', 'bucket')
            ->map(fn ($v): int => (int) $v)
            ->all();
        $top = $this->topWithOther($counts);
        $names = Occupation::query()->whereIn('id', array_filter(array_keys($top), fn ($k): bool => ! str_starts_with((string) $k, '__')))->get(['id', 'name_en', 'name_am', 'isco_code'])->keyBy(fn ($o): string => (string) $o->id);
        $categories = array_map(fn ($key): array => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_linked'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null),
        }, array_keys($top));

        return $this->chart('occupations', 'hbar', 'situationRoom.sources.positions', $categories, [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => array_values($top)],
        ], span: 2);
    }

    private function gradeLevels(SituationContext $context): SituationPanel
    {
        $counts = WorkforceQueries::assignments($context)
            ->leftJoin('positions as p', 'p.id', '=', 'ea.position_id')
            ->selectRaw("COALESCE(NULLIF(TRIM(p.grade_level), ''), '__none') AS bucket, COUNT(*) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($v): int => (int) $v)
            ->all();
        $top = $this->topWithOther($counts);
        $categories = array_map(fn ($key): array|string => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_recorded'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => (string) $key,
        }, array_keys($top));

        return $this->chart('grade_levels', 'bar', 'situationRoom.sources.positions', $categories, [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => array_values($top)],
        ]);
    }
}
