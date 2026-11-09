<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Models\PositionService;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service Delivery: the services positions offer, how often the register
 * records work on them, and what the public said about them. Public
 * submissions can only name a service through an employee QR code; the
 * share that does not is shown, so the gap is visible rather than hidden.
 */
final class ServiceDeliveryAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.position_services';

    public function panels(SituationContext $context): array
    {
        return [
            'service_kpis' => fn (): SituationPanel => $this->kpis($context),
            'service_activity' => fn (): SituationPanel => $this->activity($context),
            'service_public_table' => fn (): SituationPanel => $this->publicTable($context),
        ];
    }

    private function services(SituationContext $context): Builder
    {
        $query = DB::table('position_services as s')->whereNull('s.deleted_at')->where('s.is_active', true);
        $context->scope->apply($query, 's.organization_id');

        return $query;
    }

    private function submissions(SituationContext $context): Builder
    {
        $query = DB::table('employee_service_feedback as f')->where('f.status', '!=', 'hidden');
        $context->scope->apply($query, 'f.organization_id');

        return $this->inPeriod($query, 'f.submitted_at', $context->period());
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $submissions = $this->submissions($context);
        $total = (clone $submissions)->count();
        $linked = (clone $submissions)->whereNotNull('f.position_service_id')->count();
        $activities = $this->inPeriod(DailyOperationsAnalytics::base($context), 'l.activity_date', $context->period(), true)
            ->join('daily_activity_items as i', 'i.daily_activity_log_id', '=', 'l.id')
            ->whereNotNull('i.position_service_id');

        return SituationPanel::kpis('service_kpis', $this->title('service_kpis'), self::SOURCE, [
            $this->kpi('services_active', $this->services($context)->count()),
            $this->kpi('services_with_public_voice', (clone $submissions)->whereNotNull('f.position_service_id')->distinct()->count('f.position_service_id')),
            $this->kpi('submissions_linked_to_service', $this->percent($linked, $total), ['unit' => '%']),
            $this->kpi('service_activities_recorded', (clone $activities)->count()),
        ]);
    }

    private function activity(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy(
            $this->inPeriod(DailyOperationsAnalytics::base($context), 'l.activity_date', $context->period(), true)
                ->where('l.status', '!=', 'draft')
                ->join('daily_activity_items as i', 'i.daily_activity_log_id', '=', 'l.id')
                ->whereNotNull('i.position_service_id'),
            'i.position_service_id',
        );
        $top = $this->topWithOther($counts);

        return $this->chart('service_activity', 'hbar', 'situationRoom.sources.daily_activity', $this->serviceLabels(array_keys($top)), [
            ['key' => 'activities', 'label' => SituationLabel::key('situationRoom.series.activities'), 'values' => array_values($top)],
        ], span: 2);
    }

    /** Per service named by the public: received by type and average rating, in service-name order. */
    private function publicTable(SituationContext $context): SituationPanel
    {
        $rows = (clone $this->submissions($context))
            ->whereNotNull('f.position_service_id')
            ->selectRaw('f.position_service_id AS service_id')
            ->selectRaw("SUM(CASE WHEN f.type = 'feedback' THEN 1 ELSE 0 END) AS feedback_count")
            ->selectRaw("SUM(CASE WHEN f.type = 'complaint' THEN 1 ELSE 0 END) AS complaint_count")
            ->selectRaw("SUM(CASE WHEN f.type = 'suggestion' THEN 1 ELSE 0 END) AS tip_count")
            ->selectRaw("AVG(CASE WHEN f.type = 'feedback' THEN f.rating END) AS average_rating")
            ->groupBy('f.position_service_id')
            ->get();

        if ($rows->isEmpty()) {
            return $this->notice('service_public_table', 'situationRoom.sources.public_submissions', 'SERVICE_LINKAGE_REQUIRED', 3);
        }

        $labels = $this->serviceLabels($rows->pluck('service_id')->map(fn ($id): string => (string) $id)->all());
        $table = $rows->values()->map(fn ($row, int $i): array => [
            'service' => $labels[$i],
            'feedback' => (int) $row->feedback_count,
            'average_rating' => $row->average_rating === null ? null : round((float) $row->average_rating, 2),
            'complaints' => (int) $row->complaint_count,
            'tip_offs' => (int) $row->tip_count,
        ])->sortBy(fn (array $r): string => mb_strtolower((string) ($r['service']['en'] ?? '')))->values()->take(100)->all();

        return SituationPanel::table('service_public_table', $this->title('service_public_table'), $this->hint('service_public_table'), 'situationRoom.sources.public_submissions', [
            ['key' => 'service', 'label' => SituationLabel::key('situationRoom.columns.service'), 'type' => 'label'],
            ['key' => 'feedback', 'label' => SituationLabel::key('situationRoom.columns.feedback'), 'type' => 'number'],
            ['key' => 'average_rating', 'label' => SituationLabel::key('situationRoom.kpi.average_rating'), 'type' => 'number'],
            ['key' => 'complaints', 'label' => SituationLabel::key('situationRoom.columns.complaints'), 'type' => 'number'],
            ['key' => 'tip_offs', 'label' => SituationLabel::key('situationRoom.columns.tip_offs'), 'type' => 'number'],
        ], $table, span: 3);
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, ?string>>
     */
    private function serviceLabels(array $ids): array
    {
        $names = PositionService::withTrashed()->whereIn('id', array_filter($ids, fn ($k): bool => $k !== '__other'))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($s): string => (string) $s->id);

        return array_map(fn ($key): array => $key === '__other'
            ? SituationLabel::key('situationRoom.values.common.other')
            : SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null), $ids);
    }
}
