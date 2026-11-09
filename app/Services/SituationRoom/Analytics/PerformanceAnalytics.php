<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Contracts\Data\AgreementStatus;
use Modules\Performance\Contracts\Data\AppealStatus;
use Modules\Performance\Contracts\Data\PlanStatus;
use Modules\Performance\Contracts\Data\ResultStatus;

/**
 * Performance (EPMS) progress for the current cycle(s): how far planning,
 * agreements, results and appeals have got. The rating distribution counts
 * released results only and applies the small-group rule, so a focused view
 * of a small office never shows how one person was rated. No individual
 * scores, no ranking of organizations.
 */
final class PerformanceAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.performance';

    public function panels(SituationContext $context): array
    {
        return [
            'performance_kpis' => fn (): SituationPanel => $this->kpis($context),
            'performance_plans' => fn (): SituationPanel => $this->statusChart($context, 'performance_plans', 'performance_plans', 'plan_status', array_column(PlanStatus::cases(), 'value')),
            'performance_agreements' => fn (): SituationPanel => $this->statusChart($context, 'performance_agreements', 'employee_performance_agreements', 'agreement_status', array_column(AgreementStatus::cases(), 'value')),
            'performance_results' => fn (): SituationPanel => $this->statusChart($context, 'performance_results', 'performance_results', 'result_status', array_column(ResultStatus::cases(), 'value'), currentOnly: true),
            'performance_ratings' => fn (): SituationPanel => $this->ratings($context),
            'performance_appeals' => fn (): SituationPanel => $this->statusChart($context, 'performance_appeals', 'performance_appeals', 'appeal_status', array_column(AppealStatus::cases(), 'value')),
        ];
    }

    /** @return list<string> */
    private function currentCycles(): array
    {
        return DB::table('performance_cycles')->where('is_current', true)->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $cycles = $this->currentCycles();

        if ($cycles === []) {
            return $this->notice('performance_kpis', self::SOURCE, 'NO_CURRENT_CYCLE', 3);
        }

        $agreements = $this->scoped($context, 'employee_performance_agreements', $cycles);
        $results = $this->scoped($context, 'performance_results', $cycles)->where('t.is_current', true);
        $headcount = WorkforceQueries::headcount($context);
        $agreed = (clone $agreements)->whereIn('t.status', [AgreementStatus::Agreed->value, AgreementStatus::Active->value, AgreementStatus::UnderReview->value, AgreementStatus::Finalized->value])->distinct()->count('t.employee_id');

        return SituationPanel::kpis('performance_kpis', $this->title('performance_kpis'), self::SOURCE, [
            $this->kpi('plans_published', $this->scoped($context, 'performance_plans', $cycles)->where('t.status', PlanStatus::Published->value)->count()),
            $this->kpi('agreements_in_place', $agreed),
            $this->kpi('agreement_coverage', $this->percent($agreed, $headcount), ['unit' => '%']),
            $this->kpi('results_released', (clone $results)->where('t.status', ResultStatus::Released->value)->count()),
            $this->kpi('appeals_open', $this->scoped($context, 'performance_appeals', $cycles)->whereIn('t.status', [AppealStatus::Submitted->value, AppealStatus::UnderReview->value])->count()),
        ]);
    }

    /** @param  list<string>  $buckets */
    private function statusChart(SituationContext $context, string $key, string $table, string $labels, array $buckets, bool $currentOnly = false): SituationPanel
    {
        $cycles = $this->currentCycles();

        if ($cycles === []) {
            return $this->notice($key, self::SOURCE, 'NO_CURRENT_CYCLE');
        }

        $query = $this->scoped($context, $table, $cycles);
        if ($currentOnly) {
            $query->where('t.is_current', true);
        }
        $counts = $this->countBy($query, 't.status');

        return $this->chart($key, 'bar', self::SOURCE, $this->valueLabels($labels, $buckets), [
            ['key' => 'count', 'label' => SituationLabel::key('situationRoom.series.count'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function ratings(SituationContext $context): SituationPanel
    {
        $cycles = $this->currentCycles();

        if ($cycles === []) {
            return $this->notice('performance_ratings', self::SOURCE, 'NO_CURRENT_CYCLE');
        }

        $rows = $this->scoped($context, 'performance_results', $cycles)
            ->where('t.is_current', true)
            ->where('t.status', ResultStatus::Released->value)
            ->selectRaw("COALESCE(t.rating_label_en, '') AS label_en, COALESCE(t.rating_label_am, '') AS label_am, COUNT(*) AS total")
            ->groupBy('t.rating_label_en', 't.rating_label_am')
            ->orderBy('t.rating_label_en')
            ->get();

        return $this->chart('performance_ratings', 'bar', self::SOURCE,
            $rows->map(fn ($r): array => SituationLabel::names($r->label_en ?: null, $r->label_am ?: null))->all(),
            [['key' => 'count', 'label' => SituationLabel::key('situationRoom.series.count'), 'values' => $this->suppressSeries($rows->map(fn ($r): int => (int) $r->total)->all())]],
            span: 2,
        );
    }

    /** @param  list<string>  $cycles */
    private function scoped(SituationContext $context, string $table, array $cycles): Builder
    {
        $query = DB::table("{$table} as t")->whereIn('t.cycle_id', $cycles);
        $context->scope->apply($query, 't.organization_id');

        return $query;
    }
}
