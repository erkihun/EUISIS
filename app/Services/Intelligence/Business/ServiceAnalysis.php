<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Models\PositionService;
use App\Models\Service;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\SituationRoom\Analytics\DailyOperationsAnalytics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationMemo;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service Intelligence: per catalogue service, the work the Daily Activity
 * Register recorded on it and what the public said about it — ONLY where a
 * record names the service by its id (daily_activity_items.position_service_id,
 * employee_service_feedback.position_service_id / service_id). A service a
 * visitor only described in words is counted as unlinked and never guessed
 * from the text. When fewer than half of the period's submissions carry a
 * link the page leads with SERVICE_LINKAGE_REQUIRED so the gap is the first
 * thing a reader sees (NEEDS_DATA_SERVICE_LINKAGE).
 */
final class ServiceAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const LINKAGE_MINIMUM_PERCENT = 50.0;

    public function panels(SituationContext $context): array
    {
        $panels = [];
        if ($this->linkageIsLow($context)) {
            $panels['service_linkage'] = fn (): SituationPanel => $this->notice('service_linkage', $this->source('public_submissions'), 'SERVICE_LINKAGE_REQUIRED', 3);
        }

        return $panels + [
            'service_kpis' => fn (): SituationPanel => $this->kpis($context),
            'service_activity' => fn (): SituationPanel => $this->activity($context),
            'service_submissions' => fn (): SituationPanel => $this->submissionsChart($context),
            'service_ratings' => fn (): SituationPanel => $this->ratings($context),
        ];
    }

    public function headline(SituationContext $context): SituationPanel
    {
        $l = $this->linkage($context);

        return SituationPanel::kpis('service_headline', $this->title('service_headline'), $this->source('services'), [
            $this->kpi('services_active', $this->services($context)->count()),
            $this->kpi('service_activities', $this->activities($context)->count()),
            $this->kpi('submissions_linked_share', $l['share'], ['unit' => '%']),
        ], $this->hint('service_headline'));
    }

    public function insights(SituationContext $context, array $panels): array
    {
        $l = $this->linkage($context);
        if ($l['total'] === 0 || ! $this->linkageIsLow($context)) {
            return [];
        }

        return [$this->insight('service_linkage_low', 'info', [
            'percent' => $l['share'] ?? 0, 'count' => $l['total'] - $l['linked'], 'current' => $l['total'],
        ], [
            $this->evidence('submissions_in_period', $l['total']),
            $this->evidence('linked_to_service', $l['linked']),
            $this->evidence('share', ($l['share'] ?? 0).' %'),
        ], source: $this->source('public_submissions'), metric: 'submissions_linked_share', href: $this->link('service-feedback.organization-qr.index'))];
    }

    private function services(SituationContext $context): Builder
    {
        $query = DB::table('position_services as s')->whereNull('s.deleted_at')->where('s.is_active', true);
        $context->scope->apply($query, 's.organization_id');

        return $query;
    }

    /** Submissions of the period in scope, every type, moderated-out entries excluded. */
    private function submissions(SituationContext $context): Builder
    {
        $query = DB::table('employee_service_feedback as f')->where('f.status', '!=', 'hidden');
        $context->scope->apply($query, 'f.organization_id');

        return $query->whereBetween('f.submitted_at', $this->bounds($context->period()));
    }

    /** Register items of reported days in the period that name a service. */
    private function activities(SituationContext $context): Builder
    {
        return DailyOperationsAnalytics::base($context)
            ->whereBetween('l.activity_date', $this->bounds($context->period(), true))
            ->where('l.status', '!=', 'draft')
            ->join('daily_activity_items as i', 'i.daily_activity_log_id', '=', 'l.id')
            ->whereNotNull('i.position_service_id');
    }

    /** @return array{total: int, linked: int, share: ?float} */
    public function linkage(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:service:linkage'), function () use ($context): array {
            $total = (clone $this->submissions($context))->count();
            $linked = (clone $this->submissions($context))->where(fn (Builder $q) => $q->whereNotNull('f.position_service_id')->orWhereNotNull('f.service_id'))->count();

            return ['total' => $total, 'linked' => $linked, 'share' => $this->percent($linked, $total)];
        });
    }

    private function linkageIsLow(SituationContext $context): bool
    {
        $l = $this->linkage($context);

        return $l['total'] > 0 && ($l['share'] ?? 0) < self::LINKAGE_MINIMUM_PERCENT;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $l = $this->linkage($context);

        return SituationPanel::kpis('service_kpis', $this->title('service_kpis'), $this->source('services'), [
            $this->kpi('services_active', $this->services($context)->count()),
            $this->kpi('services_with_activity', (clone $this->activities($context))->distinct()->count('i.position_service_id')),
            $this->kpi('service_activities', (clone $this->activities($context))->count()),
            $this->kpi('services_with_public_voice', (clone $this->submissions($context))->whereNotNull('f.position_service_id')->distinct()->count('f.position_service_id')),
            $this->kpi('submissions_linked_share', $l['share'], ['unit' => '%']),
            $this->kpi('submissions_unlinked', $l['total'] - $l['linked']),
        ], $this->hint('service_kpis'));
    }

    private function activity(SituationContext $context): SituationPanel
    {
        $top = $this->topWithOther($this->countBy($this->activities($context), 'i.position_service_id'));

        return $this->chart('service_activity', 'hbar', $this->source('daily_activity'), $this->serviceLabels(array_keys($top)), [
            ['key' => 'activities', 'label' => SituationLabel::key('situationRoom.series.activities'), 'values' => array_values($top)],
        ], span: 2);
    }

    private function submissionsChart(SituationContext $context): SituationPanel
    {
        $linked = (clone $this->submissions($context))->where(fn (Builder $q) => $q->whereNotNull('f.position_service_id')->orWhereNotNull('f.service_id'));
        $counts = $this->countBy($linked, 'COALESCE(f.position_service_id, f.service_id)');
        if ($counts === []) {
            return $this->notice('service_submissions', $this->source('public_submissions'), 'SERVICE_LINKAGE_REQUIRED', 2);
        }
        $top = $this->topWithOther($counts);

        return $this->chart('service_submissions', 'hbar', $this->source('public_submissions'), $this->serviceLabels(array_keys($top)), [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => array_values($top)],
        ], span: 2);
    }

    /** Per linked service: received by type and the average rating of its feedback, in name order. */
    private function ratings(SituationContext $context): SituationPanel
    {
        $rows = (clone $this->submissions($context))
            ->where(fn (Builder $q) => $q->whereNotNull('f.position_service_id')->orWhereNotNull('f.service_id'))
            ->selectRaw('COALESCE(f.position_service_id, f.service_id) AS service_id')
            ->selectRaw("SUM(CASE WHEN f.type = 'feedback' THEN 1 ELSE 0 END) AS feedback_count")
            ->selectRaw("SUM(CASE WHEN f.type = 'complaint' THEN 1 ELSE 0 END) AS complaint_count")
            ->selectRaw("SUM(CASE WHEN f.type = 'suggestion' THEN 1 ELSE 0 END) AS tip_count")
            ->selectRaw("AVG(CASE WHEN f.type = 'feedback' THEN f.rating END) AS average_rating")
            ->groupBy('service_id')
            ->get();
        if ($rows->isEmpty()) {
            return $this->notice('service_ratings', $this->source('public_submissions'), 'SERVICE_LINKAGE_REQUIRED', 3);
        }
        $labels = $this->serviceLabels($rows->pluck('service_id')->map(fn ($id): string => (string) $id)->all());
        $table = $rows->values()->map(fn ($row, int $i): array => [
            'service' => $labels[$i],
            'feedback' => (int) $row->feedback_count,
            'average_rating' => $row->average_rating === null ? null : round((float) $row->average_rating, 2),
            'complaints' => (int) $row->complaint_count,
            'tip_offs' => (int) $row->tip_count,
        ])->sortBy(fn (array $r): string => mb_strtolower((string) ($r['service']['en'] ?? '')))->values()->take(100)->all();

        return SituationPanel::table('service_ratings', $this->title('service_ratings'), $this->hint('service_ratings'), $this->source('public_submissions'), [
            $this->column('service', 'label'),
            $this->column('feedback'),
            $this->column('average_rating'),
            $this->column('complaints'),
            $this->column('tip_offs'),
        ], $table, span: 3);
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, ?string>>
     */
    private function serviceLabels(array $ids): array
    {
        $real = array_filter($ids, fn ($k): bool => $k !== '__other');
        $names = PositionService::withTrashed()->whereIn('id', $real)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($s): string => (string) $s->id)
            ->union(Service::withTrashed()->whereIn('id', $real)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($s): string => (string) $s->id));

        return array_map(fn ($key): array => $key === '__other'
            ? SituationLabel::key('situationRoom.values.common.other')
            : SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null), $ids);
    }
}
