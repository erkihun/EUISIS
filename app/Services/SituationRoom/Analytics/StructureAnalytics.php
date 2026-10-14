<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Organization Structure: what the scope is made of — organizations, units,
 * positions and establishment — and, per roll-up row, staffing against
 * approved capacity. Rows are in name order.
 */
final class StructureAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.structure';

    public function panels(SituationContext $context): array
    {
        return [
            'structure_kpis' => fn (): SituationPanel => $this->kpis($context),
            'structure_hierarchy' => fn (): SituationPanel => $this->hierarchy($context),
            'structure_organization_types' => fn (): SituationPanel => $this->organizationTypes($context),
            'structure_unit_types' => fn (): SituationPanel => $this->unitTypes($context),
            'structure_table' => fn (): SituationPanel => $this->table($context),
        ];
    }

    private function organizations(SituationContext $context): Builder
    {
        $query = DB::table('organizations as o')->whereNull('o.deleted_at');
        $context->scope->apply($query, 'o.id');

        return $query;
    }

    private function units(SituationContext $context): Builder
    {
        $query = DB::table('organization_units as u')->whereNull('u.deleted_at');
        $context->scope->apply($query, 'u.organization_id');

        return $query;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $capacity = WorkforceQueries::capacityByOrganization($context);

        return SituationPanel::kpis('structure_kpis', $this->title('structure_kpis'), self::SOURCE, [
            $this->kpi('organizations', $this->organizations($context)->count()),
            $this->kpi('units', $this->units($context)->count()),
            $this->kpi('positions', array_sum(array_column($capacity, 'positions'))),
            $this->kpi('capacity', array_sum(array_column($capacity, 'capacity'))),
            $this->kpi('headcount', WorkforceQueries::headcount($context)),
        ]);
    }

    /** Without a published hierarchy there is no authoritative reporting line to roll up along. */
    private function hierarchy(SituationContext $context): SituationPanel
    {
        $rollup = $this->rollup->for($context->scope);

        return $rollup['hierarchy']
            ? $this->notice('structure_hierarchy', self::SOURCE, 'HIERARCHY_PUBLISHED', 3)
            : $this->notice('structure_hierarchy', self::SOURCE, 'NEEDS_POLICY_DECISION_REPORTING_HIERARCHY', 3);
    }

    private function organizationTypes(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->organizations($context), "COALESCE(CAST(o.organization_type_id AS VARCHAR(64)), '__none')");
        $names = OrganizationType::query()->whereIn('id', array_filter(array_keys($counts), fn ($k): bool => $k !== '__none'))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($t): string => (string) $t->id);

        return $this->chart('structure_organization_types', 'donut', self::SOURCE, array_map(fn ($key): array => $key === '__none'
            ? SituationLabel::key('situationRoom.values.common.not_recorded')
            : SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null), array_keys($counts)), [
                ['key' => 'organizations', 'label' => SituationLabel::key('situationRoom.kpi.organizations'), 'values' => array_values($counts)],
            ]);
    }

    private function unitTypes(SituationContext $context): SituationPanel
    {
        $top = $this->topWithOther($this->countBy($this->units($context), "COALESCE(CAST(u.organization_unit_type_id AS VARCHAR(64)), '__none')"));
        $names = OrganizationUnitType::withTrashed()->whereIn('id', array_filter(array_keys($top), fn ($k): bool => ! str_starts_with((string) $k, '__')))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($t): string => (string) $t->id);

        return $this->chart('structure_unit_types', 'hbar', self::SOURCE, array_map(fn ($key): array => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_recorded'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null),
        }, array_keys($top)), [
            ['key' => 'units', 'label' => SituationLabel::key('situationRoom.kpi.units'), 'values' => array_values($top)],
        ]);
    }

    /** One row per roll-up row: units, positions, capacity, headcount, vacancies. */
    private function table(SituationContext $context): SituationPanel
    {
        $capacity = WorkforceQueries::capacityByOrganization($context);
        $columns = [
            'units' => $this->rolledUp($context, $this->countBy($this->units($context), 'u.organization_id')),
            'positions' => $this->rolledUp($context, array_map(fn (array $r): int => $r['positions'], $capacity)),
            'capacity' => $this->rolledUp($context, array_map(fn (array $r): int => $r['capacity'], $capacity)),
            'headcount' => $this->rolledUp($context, WorkforceQueries::headcountByOrganization($context)),
            'vacant' => $this->rolledUp($context, array_map(fn (array $r): int => $r['vacant'], $capacity)),
        ];
        $order = $columns['positions'];
        $rows = [];

        foreach ($order['ids'] as $i => $id) {
            $row = ['organization' => $order['categories'][$i]];
            foreach ($columns as $name => $data) {
                $byId = array_combine($data['ids'], $data['values']) ?: [];
                $row[$name] = (int) ($byId[$id] ?? 0);
            }
            $row['vacancy_rate'] = $this->percent($row['vacant'], $row['capacity']);
            $rows[] = $row;
        }

        return SituationPanel::table('structure_table', $this->title('structure_table'), $this->hint('structure_table'), self::SOURCE, [
            ['key' => 'organization', 'label' => SituationLabel::key('situationRoom.columns.organization'), 'type' => 'label'],
            ['key' => 'units', 'label' => SituationLabel::key('situationRoom.kpi.units'), 'type' => 'number'],
            ['key' => 'positions', 'label' => SituationLabel::key('situationRoom.kpi.positions'), 'type' => 'number'],
            ['key' => 'capacity', 'label' => SituationLabel::key('situationRoom.kpi.capacity'), 'type' => 'number'],
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'type' => 'number'],
            ['key' => 'vacant', 'label' => SituationLabel::key('situationRoom.kpi.vacant'), 'type' => 'number'],
            ['key' => 'vacancy_rate', 'label' => SituationLabel::key('situationRoom.kpi.vacancy_rate'), 'type' => 'percent'],
        ], $rows, span: 3);
    }
}
