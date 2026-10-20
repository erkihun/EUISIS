<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Enums\ServiceFeedbackType;
use App\Services\Intelligence\Analysis\Baseline;
use App\Services\Intelligence\Contracts\TechnicalSignalSource;
use App\Services\Intelligence\Insight;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Services\SituationRoom\Analytics\DailyOperationsAnalytics;
use App\Services\SituationRoom\Analytics\WorkforceQueries;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationPeriod;
use App\Services\SituationRoom\SituationSettings;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Executive Intelligence: the priority view for leaders. One headline strip
 * (vacancy rate, open complaints, EPMS completion, data-quality issues),
 * the roll-up rows where a measure is abnormal, and the insights of the
 * other business areas ranked by severity. `intelligence.executive.view` is
 * its own grant: it reads aggregates only — never a person, never a stack
 * trace — and does not imply the area grants it summarises.
 */
final class ExecutiveAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const ATTENTION_MINIMUM_COMPLAINTS = 5;

    public const ACTIVITY_DROP_PERCENT = 40.0;

    public const ACTIVITY_MINIMUM = 10;

    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly IntelligenceSettings $intelligence,
        private readonly WorkforceAnalysis $workforce,
        private readonly PublicVoiceAnalysis $publicVoice,
        private readonly PerformanceAnalysis $performance,
        private readonly DataQualityAnalysis $dataQuality,
        private readonly PublicFeedbackVisibility $visibility,
        private readonly TechnicalSignalSource $technical,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        return [
            'executive_kpis' => fn (): SituationPanel => $this->headline($context),
            'executive_attention' => fn (): SituationPanel => $this->attention($context),
        ];
    }

    public function headline(SituationContext $context): SituationPanel
    {
        $figures = $this->workforce->fiveFigures($context);
        $voice = $this->visibility->visibleTargetTypes($context->user) === [] ? null : $this->publicVoice->backlogCounts($context);
        $epms = $this->performance->progress($context);
        $quality = $this->dataQuality->summaryCounts($context);

        return SituationPanel::kpis('executive_kpis', $this->title('executive_kpis'), $this->source('situation_room'), [
            $this->kpi('vacancy_rate', $figures['vacancy_rate'], ['unit' => '%', 'threshold' => $this->settings->threshold('vacancy_rate_percent')]),
            $this->kpi('complaints_open', $voice['open_end'] ?? null),
            $this->kpi('agreement_coverage', $epms['coverage'], ['unit' => '%']),
            $this->kpi('checks_failing', $quality['failing'] + $quality['warning']),
        ], $this->hint('executive_kpis'));
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $insights = [];
        foreach ([$this->workforce, $this->publicVoice, $this->performance, $this->dataQuality] as $analysis) {
            try {
                $insights = [...$insights, ...$analysis->insights($context, [])];
            } catch (Throwable $e) {
                // One failing area must not silence the others; the service marks the payload as errored.
                report($e);
            }
        }
        $activity = $this->dailyActivityDrop($context);
        if ($activity !== null) {
            $insights[] = $activity;
        }
        usort($insights, fn (Insight $a, Insight $b): int => $b->weight() <=> $a->weight());

        return $insights;
    }

    /**
     * The roll-up rows (own office and each direct child with its subtree)
     * where a measure is abnormal: vacancy rate above the configured limit,
     * or a vacancy rate / complaint count that is a robust outlier among the
     * rows. Rows without a flag are not listed, so the table is short.
     */
    private function attention(SituationContext $context): SituationPanel
    {
        $capacity = WorkforceQueries::capacityByOrganization($context);
        $vacant = $this->rolledUp($context, array_map(fn (array $r): int => $r['vacant'], $capacity));
        $slots = $this->rolledUp($context, array_map(fn (array $r): int => $r['capacity'], $capacity));
        $period = $context->period();
        $complaints = $this->visibility->visibleTargetTypes($context->user) === []
            ? ['ids' => $vacant['ids'], 'values' => array_fill(0, count($vacant['ids']), 0)]
            : $this->rolledUp($context, $this->countBy($this->inPeriod($this->publicVoice->submissions($context, ServiceFeedbackType::Complaint), 'f.submitted_at', $period), 'f.organization_id'));
        $threshold = $this->settings->threshold('vacancy_rate_percent');
        $sigma = $this->intelligence->anomalySigma();

        $rows = [];
        $rates = [];
        foreach ($vacant['ids'] as $i => $id) {
            $rates[$i] = $this->percent($vacant['values'][$i], $slots['values'][$i]);
        }
        $rateHistory = array_values(array_filter($rates, fn ($r): bool => $r !== null));
        $complaintsById = array_combine($complaints['ids'], $complaints['values']) ?: [];
        foreach ($vacant['ids'] as $i => $id) {
            $rate = $rates[$i];
            $received = (int) ($complaintsById[$id] ?? 0);
            $flags = [];
            if ($threshold !== null && $rate !== null && $rate > $threshold) {
                $flags[] = 'vacancy_threshold';
            } elseif ($rate !== null && Baseline::isAbnormallyHigh($rate, $rateHistory, $sigma)) {
                $flags[] = 'vacancy_outlier';
            }
            if (Baseline::isAbnormallyHigh($received, $complaints['values'], $sigma, self::ATTENTION_MINIMUM_COMPLAINTS)) {
                $flags[] = 'complaints_outlier';
            }
            if ($flags === []) {
                continue;
            }
            $rows[] = [
                'organization' => $vacant['categories'][$i],
                'vacancy_rate' => $rate,
                'vacant_positions' => (int) $vacant['values'][$i],
                'complaints_received' => $received,
                'attention' => $this->value('attention', count($flags) > 1 ? 'several' : $flags[0]),
            ];
        }

        if ($rows === []) {
            return $this->notice('executive_attention', $this->source('situation_room'), 'ALL_CLEAR', 3);
        }

        return SituationPanel::table('executive_attention', $this->title('executive_attention'), $this->hint('executive_attention'), $this->source('situation_room'), [
            $this->column('organization', 'label'),
            $this->column('vacancy_rate', 'percent'),
            $this->column('vacant_positions'),
            $this->column('complaints_received'),
            $this->column('attention', 'label'),
        ], $rows, span: 3);
    }

    /**
     * Daily Activity days reported this period against the previous one; a
     * drop of 40% or more is reported with, when the technical domain knows,
     * whether the register's routes failed meanwhile — a possible influence,
     * never a cause.
     */
    private function dailyActivityDrop(SituationContext $context): ?Insight
    {
        $period = $context->period();
        $previous = $period->previous();
        $reported = fn (SituationPeriod $p): int => $this->reportedDays($context)->whereBetween('l.activity_date', $this->bounds($p, true))->count();
        $now = $reported($period);
        $before = $reported($previous);
        if ($before < self::ACTIVITY_MINIMUM || $now > $before * (1 - self::ACTIVITY_DROP_PERCENT / 100)) {
            return null;
        }
        $technical = $this->technical->failuresFor(RouteGroups::DAILY_ACTIVITY, $period->from, $period->to);
        $params = ['current' => $now, 'baseline' => $before, 'percent' => Baseline::changePercent($now, $before) ?? 0];
        $evidence = [
            $this->evidence('window', $period->from->toDateString().' – '.$period->to->toDateString()),
            $this->evidence('days_reported', $now),
            $this->evidence('previous', $before),
        ];
        if ($technical['known'] && (($technical['failure_rate_percent'] ?? 0) >= PublicVoiceAnalysis::FAILURE_RATE_PERCENT || $technical['incidents'] > 0)) {
            $evidence[] = $this->evidence('route', RouteGroups::DAILY_ACTIVITY);
            $evidence[] = $this->evidence('failure_rate', ($technical['failure_rate_percent'] ?? 0).' %');
            $evidence[] = $this->evidence('incidents', $technical['incidents']);

            return $this->insight('daily_activity_drop_with_failures', 'medium', $params + ['failure_rate' => $technical['failure_rate_percent'] ?? 0, 'incidents' => $technical['incidents']], $evidence,
                $this->change($now, $before), confidence: 'medium', source: $this->source('daily_activity'), metric: 'days_reported');
        }

        return $this->insight('daily_activity_drop', 'low', $params, $evidence, $this->change($now, $before), source: $this->source('daily_activity'), metric: 'days_reported');
    }

    private function reportedDays(SituationContext $context): Builder
    {
        return DailyOperationsAnalytics::base($context)->where('l.status', '!=', 'draft');
    }
}
