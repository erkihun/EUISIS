<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationSettings;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringIndicatorSnapshot;
use Modules\ServiceMonitoring\Contracts\ServiceMonitoringIndicators;

/**
 * Service Delivery Monitoring (D26): report compliance, people held
 * accountable by final action, verified digital services, service standard
 * performance and overdue support actions. Aggregated and unnamed; the
 * figures come from the module's public contract, already confined to the
 * viewer's organizations. The existing service_delivery category is a
 * different thing (public feedback on services) and is not touched.
 */
final class ServiceMonitoringAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.service_monitoring';

    private ?ServiceMonitoringIndicatorSnapshot $snapshot = null;

    public function __construct(OrganizationRollup $rollup, SituationSettings $settings, private readonly ServiceMonitoringIndicators $indicators)
    {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        return [
            'sm_kpis' => fn (): SituationPanel => $this->kpis($context),
            'sm_compliance' => fn (): SituationPanel => $this->compliance($context),
            'sm_accountability' => fn (): SituationPanel => $this->accountability($context),
            'sm_standards' => fn (): SituationPanel => $this->standards($context),
        ];
    }

    private function figures(SituationContext $context): ServiceMonitoringIndicatorSnapshot
    {
        $period = $context->period();

        return $this->snapshot ??= $this->indicators->snapshot($context->scope->organizationIds, $period->from, $period->to);
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $f = $this->figures($context);

        return SituationPanel::kpis('sm_kpis', $this->title('sm_kpis'), self::SOURCE, [
            $this->kpi('sm_on_time', $f->compliance['on_time']),
            $this->kpi('sm_late', $f->compliance['late']),
            $this->kpi('sm_missing', $f->compliance['missing']),
            $this->kpi('sm_in_progress', $f->compliance['in_progress']),
            $this->kpi('sm_digital_verified', $f->digitalServicesVerified),
            $this->kpi('sm_overdue_actions', $f->overdueActionItems),
        ]);
    }

    private function compliance(SituationContext $context): SituationPanel
    {
        $f = $this->figures($context);
        $buckets = ['on_time', 'late', 'missing', 'in_progress'];

        return $this->chart('sm_compliance', 'donut', self::SOURCE, $this->valueLabels('sm_compliance', $buckets), [
            ['key' => 'sm_institutions', 'label' => SituationLabel::key('situationRoom.series.sm_institutions'), 'values' => array_map(fn (string $b): int => $f->compliance[$b], $buckets)],
        ]);
    }

    private function accountability(SituationContext $context): SituationPanel
    {
        $f = $this->figures($context);
        $actions = array_keys($f->accountabilityByAction);

        return $this->chart('sm_accountability', 'hbar', self::SOURCE, $this->valueLabels('sm_final_action', $actions), [
            ['key' => 'sm_people', 'label' => SituationLabel::key('situationRoom.series.sm_people'), 'values' => $this->suppressSeries(array_values($f->accountabilityByAction))],
        ]);
    }

    private function standards(SituationContext $context): SituationPanel
    {
        $s = $this->figures($context)->standardPerformance;

        return $this->chart('sm_standards', 'bar', self::SOURCE, $this->valueLabels('sm_service_level', ['main', 'sub']), [
            ['key' => 'above', 'label' => SituationLabel::key('situationRoom.values.sm_standard.above'), 'values' => [$s['main_above'], $s['sub_above']]],
            ['key' => 'at', 'label' => SituationLabel::key('situationRoom.values.sm_standard.at'), 'values' => [$s['main_at'], $s['sub_at']]],
            ['key' => 'below', 'label' => SituationLabel::key('situationRoom.values.sm_standard.below'), 'values' => [$s['main_below'], $s['sub_below']]],
        ], span: 2);
    }
}
