<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\FieldWork\Contracts\Data\FieldWorkStatus;
use Modules\FieldWork\Contracts\ReadModels\FieldWorkTypeRecord;

/**
 * Field Work: who is out now (as a count), what is waiting for approval and
 * what happened in the period. No names, routes or live positions: a wall
 * or an executive sees how many, not who or where.
 */
final class FieldWorkAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.field_work';

    public function panels(SituationContext $context): array
    {
        return [
            'field_kpis' => fn (): SituationPanel => $this->kpis($context),
            'field_trend' => fn (): SituationPanel => $this->trendPanel($context),
            'field_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'field_status' => fn (): SituationPanel => $this->status($context),
            'field_destination' => fn (): SituationPanel => $this->destinations($context),
            'field_types' => fn (): SituationPanel => $this->types($context),
            'field_locations' => fn (): SituationPanel => $this->locations($context),
        ];
    }

    public static function base(SituationContext $context): Builder
    {
        $query = DB::table('field_work_requests as r');
        $context->scope->apply($query, 'r.organization_id');

        if (($unit = $context->filters->get('organization_unit_id')) !== null) {
            $query->where('r.organization_unit_id', $unit);
        }

        return $query;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $now = CarbonImmutable::now()->toDateTimeString();
        $inField = (clone self::base($context))->where('r.status', FieldWorkStatus::InField->value);
        $period = $this->inPeriod(self::base($context), 'r.starts_at', $context->period());

        return SituationPanel::kpis('field_kpis', $this->title('field_kpis'), self::SOURCE, [
            $this->kpi('in_field_now', (clone $inField)->count()),
            $this->kpi('people_in_field_now', (int) DB::table('field_work_participants as fp')->whereIn('fp.field_work_request_id', (clone $inField)->select('r.id'))->distinct()->count('fp.employee_id')),
            $this->kpi('past_expected_return', (clone $inField)->where('r.expected_return_at', '<', $now)->count()),
            $this->kpi('awaiting_approval', (clone self::base($context))->where('r.status', FieldWorkStatus::PendingSupervisorApproval->value)->count()),
            $this->kpi('field_started', (clone $period)->whereNotIn('r.status', [FieldWorkStatus::Draft->value])->count()),
            $this->kpi('field_completed', (clone $period)->where('r.status', FieldWorkStatus::Completed->value)->count()),
        ]);
    }

    private function trendPanel(SituationContext $context): SituationPanel
    {
        $trend = $this->trend(self::base($context)->where('r.status', '!=', FieldWorkStatus::Draft->value), 'r.starts_at', $context->period());

        return $this->chart('field_trend', 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'field_started', 'label' => SituationLabel::key('situationRoom.kpi.field_started'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod(self::base($context), 'r.starts_at', $context->period())->where('r.status', '!=', FieldWorkStatus::Draft->value), 'r.organization_id');
        $rolled = $this->rolledUp($context, $counts);

        return $this->chart('field_by_organization', 'hbar', self::SOURCE, $rolled['categories'], [
            ['key' => 'field_started', 'label' => SituationLabel::key('situationRoom.kpi.field_started'), 'values' => $rolled['values']],
        ], span: 2);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod(self::base($context), 'r.starts_at', $context->period()), 'r.status');
        $buckets = FieldWorkStatus::values();

        return $this->chart('field_status', 'donut', self::SOURCE, $this->valueLabels('field_status', $buckets), [
            ['key' => 'requests', 'label' => SituationLabel::key('situationRoom.series.requests'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function destinations(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod(self::base($context), 'r.starts_at', $context->period()), "COALESCE(r.destination_type, 'unknown')");
        ksort($counts);

        return $this->chart('field_destination', 'donut', self::SOURCE, $this->valueLabels('destination_type', array_keys($counts)), [
            ['key' => 'requests', 'label' => SituationLabel::key('situationRoom.series.requests'), 'values' => array_values($counts)],
        ]);
    }

    private function types(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod(self::base($context), 'r.starts_at', $context->period()), "COALESCE(CAST(r.field_work_type_id AS VARCHAR(64)), '__none')");
        $top = $this->topWithOther($counts);
        $names = FieldWorkTypeRecord::query()->whereIn('id', array_filter(array_keys($top), fn ($k): bool => ! str_starts_with((string) $k, '__')))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($t): string => (string) $t->id);

        return $this->chart('field_types', 'hbar', self::SOURCE, array_map(fn ($key): array => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_recorded'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null),
        }, array_keys($top)), [
            ['key' => 'requests', 'label' => SituationLabel::key('situationRoom.series.requests'), 'values' => array_values($top)],
        ]);
    }

    /**
     * A map needs destination coordinates and an approved map layer. With no
     * coordinates recorded in scope the room says GIS_DATA_NOT_AVAILABLE; with
     * coordinates but no map layer configured, MAP_LAYER_NOT_CONFIGURED.
     */
    private function locations(SituationContext $context): SituationPanel
    {
        $located = (clone self::base($context))->whereNotNull('r.expected_latitude')->whereNotNull('r.expected_longitude')->exists();

        return $this->notice('field_locations', self::SOURCE, $located ? 'MAP_LAYER_NOT_CONFIGURED' : 'GIS_DATA_NOT_AVAILABLE');
    }
}
