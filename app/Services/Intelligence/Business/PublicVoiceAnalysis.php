<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\PositionService;
use App\Models\PublicFeedbackCategory;
use App\Models\Service;
use App\Services\Intelligence\Analysis\Baseline;
use App\Services\Intelligence\Contracts\TechnicalSignalSource;
use App\Services\Intelligence\Insight;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Services\SituationRoom\Analytics\PublicVoiceAnalytics;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationSettings;
use App\Support\Database\SqlDateBucket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Public Voice Intelligence: what the public said — Feedback, Tip-offs
 * (stored as `suggestion`) and Complaints — kept strictly apart, as are the
 * two targets (an employee's QR, an institution's form). A count is a volume
 * of reports, never a score: nothing here ranks an organization or names a
 * person, and a complaint drop is never called an improvement on its own.
 *
 * Grants: the area grant opens the aggregates; which TARGETS are counted
 * follows the same per-target grants as the public-feedback inboxes
 * (PublicFeedbackVisibility::applyTargetScope), so an aggregate never carries
 * a target type the viewer could not list. Rows written before the unified
 * model carry no target type and are employee submissions.
 */
final class PublicVoiceAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const SPIKE_MINIMUM = 5;

    public const REPEATED_CATEGORY_SHARE = 40.0;

    public const REPEATED_CATEGORY_MINIMUM = 10;

    public const BACKLOG_GROWTH_PERCENT = 25.0;

    public const BACKLOG_MINIMUM = 10;

    public const UNROUTED_SHARE_PERCENT = 20.0;

    public const UNROUTED_MINIMUM = 5;

    public const DROP_PERCENT = 40.0;

    public const FAILURE_RATE_PERCENT = 5.0;

    public const TYPES = ['feedback', 'suggestion', 'complaint'];

    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly IntelligenceSettings $intelligence,
        private readonly PublicFeedbackVisibility $visibility,
        private readonly TechnicalSignalSource $technical,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        if ($this->visibility->visibleTargetTypes($context->user) === []) {
            return ['voice_kpis' => fn (): SituationPanel => $this->notice('voice_kpis', $this->source('public_submissions'), 'NO_PERMISSION', 3)];
        }

        return [
            'voice_kpis' => fn (): SituationPanel => $this->kpis($context),
            'voice_trend' => fn (): SituationPanel => $this->trendPanel($context),
            'voice_targets' => fn (): SituationPanel => $this->targets($context),
            'voice_by_organization' => fn (): SituationPanel => $this->byOrganization($context),
            'voice_status' => fn (): SituationPanel => $this->status($context),
            'complaints_by_category' => fn (): SituationPanel => $this->categories($context),
            'complaints_by_service' => fn (): SituationPanel => $this->services($context),
            'complaints_backlog' => fn (): SituationPanel => $this->backlog($context),
        ];
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if ($this->visibility->visibleTargetTypes($context->user) === []) {
            return $this->notice('voice_headline', $this->source('public_submissions'), 'NO_PERMISSION', 3);
        }
        $period = $context->period();
        $received = $this->countBy($this->inPeriod($this->submissions($context), 'f.submitted_at', $period), 'f.type');
        $b = $this->backlogCounts($context);

        return SituationPanel::kpis('voice_headline', $this->title('voice_headline'), $this->source('public_submissions'), [
            $this->kpi('complaints_received', $received['complaint'] ?? 0),
            $this->kpi('complaints_open', $b['open_end']),
            $this->kpi('tip_offs_received', $received['suggestion'] ?? 0),
            $this->kpi('feedback_received', $received['feedback'] ?? 0),
        ], $this->hint('voice_headline'));
    }

    public function insights(SituationContext $context, array $panels): array
    {
        if ($this->visibility->visibleTargetTypes($context->user) === []) {
            return [];
        }
        $insights = [];
        $period = $context->period();

        // Weekly complaint volume against the robust baseline of the weeks before.
        $weekly = $this->weeklyComplaints($context);
        $stats = Baseline::describe($weekly['history']);
        if (Baseline::isAbnormallyHigh($weekly['current'], $weekly['history'], $this->intelligence->anomalySigma(), self::SPIKE_MINIMUM)) {
            $insights[] = $this->insight('complaint_spike', 'high', [
                'current' => $weekly['current'], 'baseline' => round((float) $stats['median'], 1), 'count' => $weekly['current'],
            ], [
                $this->evidence('window', $weekly['week_from'].' – '.$weekly['week_to']),
                $this->evidence('count', $weekly['current']),
                $this->evidence('baseline_median_weekly', round((float) $stats['median'], 1)),
                $this->evidence('baseline_window', count($weekly['history']).' weeks'),
            ], $this->change($weekly['current'], $stats['median']), href: $this->link('service-submissions.index'), source: $this->source('public_submissions'), metric: 'complaints_received');
        } elseif ($stats['median'] !== null && $stats['median'] >= self::SPIKE_MINIMUM && $weekly['current'] <= $stats['median'] * (1 - self::DROP_PERCENT / 100)) {
            $insights[] = $this->complaintDrop($weekly, (float) $stats['median']);
        }

        // One category dominating the period's complaints.
        $byCategory = $this->countBy($this->inPeriod($this->submissions($context, ServiceFeedbackType::Complaint), 'f.submitted_at', $period)->whereNotNull('f.category_id'), 'f.category_id');
        $complaints = (clone $this->inPeriod($this->submissions($context, ServiceFeedbackType::Complaint), 'f.submitted_at', $period))->count();
        if ($byCategory !== [] && $complaints > 0) {
            arsort($byCategory);
            $topId = (string) array_key_first($byCategory);
            $top = $byCategory[$topId];
            $share = $this->percent($top, $complaints);
            if ($top >= self::REPEATED_CATEGORY_MINIMUM && $share !== null && $share >= self::REPEATED_CATEGORY_SHARE) {
                $category = PublicFeedbackCategory::query()->whereKey($topId)->first(['name_en', 'name_am']);
                $insights[] = $this->insight('repeated_complaint_category', 'medium', [
                    'name' => (string) ($category?->name_en ?? '?'), 'count' => $top, 'percent' => $share, 'current' => $complaints,
                ], [
                    ['label' => SituationLabel::names($category?->name_en, $category?->name_am), 'value' => $top, 'href' => null],
                    $this->evidence('complaints_in_period', $complaints),
                    $this->evidence('share', $share.' %'),
                ], href: $this->link('service-submissions.index'), source: $this->source('public_submissions'), metric: 'complaints_received');
            }
        }

        // Backlog growing across the period.
        $b = $this->backlogCounts($context);
        $growth = Baseline::changePercent($b['open_end'], $b['open_start']);
        if ($b['open_end'] >= self::BACKLOG_MINIMUM && $growth !== null && $growth > self::BACKLOG_GROWTH_PERCENT) {
            $insights[] = $this->insight('complaint_backlog_growth', $growth >= 2 * self::BACKLOG_GROWTH_PERCENT ? 'high' : 'medium', [
                'current' => $b['open_end'], 'baseline' => $b['open_start'], 'percent' => $growth, 'count' => $b['open_end'],
            ], [
                $this->evidence('open_at_period_start', $b['open_start']),
                $this->evidence('open_at_period_end', $b['open_end']),
                $this->evidence('window', $period->from->toDateString().' – '.$period->to->toDateString()),
            ], $this->change($b['open_end'], $b['open_start']), href: $this->link('service-submissions.index'), source: $this->source('public_submissions'), metric: 'complaints_open');
        }

        // Routing failures: submissions nobody could be assigned to.
        if ($b['unrouted'] >= self::UNROUTED_MINIMUM && $b['unrouted_share'] !== null && $b['unrouted_share'] >= self::UNROUTED_SHARE_PERCENT) {
            $insights[] = $this->insight('unrouted_submissions', 'medium', [
                'count' => $b['unrouted'], 'percent' => $b['unrouted_share'], 'current' => $b['routable'],
            ], [
                $this->evidence('unrouted', $b['unrouted']),
                $this->evidence('routable_received', $b['routable']),
                $this->evidence('share', $b['unrouted_share'].' %'),
            ], href: $this->link('service-submissions.index'), source: $this->source('public_submissions'), metric: 'unrouted_share');
        }

        return $insights;
    }

    /**
     * Submissions in scope, moderated-out entries excluded, confined to the
     * target types the viewer may list, narrowed by the view's filters.
     * Period is applied by each caller.
     */
    public function submissions(SituationContext $context, ?ServiceFeedbackType $type = null): Builder
    {
        $query = DB::table('employee_service_feedback as f')->where('f.status', '!=', ServiceFeedbackStatus::Hidden->value);
        if ($type !== null) {
            $query->where('f.type', $type->value);
        }
        $context->scope->apply($query, 'f.organization_id');
        $this->applyTargetScope($query, $context);

        $filters = $context->filters;
        foreach (['status' => 'f.status', 'category_id' => 'f.category_id', 'position_service_id' => 'f.position_service_id', 'organization_unit_id' => 'f.organization_unit_id'] as $key => $column) {
            if (($value = $filters->get($key)) !== null) {
                $query->where($column, $value);
            }
        }
        if (($target = $filters->get('target_type')) !== null) {
            $target === PublicFeedbackTargetType::Employee->value
                ? $query->where(fn ($q) => $q->where('f.target_type', $target)->orWhereNull('f.target_type'))
                : $query->where('f.target_type', $target);
        }

        return $query;
    }

    /** The same rule as PublicFeedbackVisibility::applyTargetScope, on a query builder. */
    private function applyTargetScope(Builder $query, SituationContext $context): void
    {
        $types = $this->visibility->visibleTargetTypes($context->user);
        if (count($types) === 2) {
            return;
        }
        if ($types === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function (Builder $q) use ($types): void {
            $q->whereIn('f.target_type', $types);
            if (in_array(PublicFeedbackTargetType::Employee->value, $types, true)) {
                $q->orWhereNull('f.target_type');
            }
        });
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $previous = $period->previous();
        $now = $this->countBy($this->inPeriod($this->submissions($context), 'f.submitted_at', $period), 'f.type');
        $before = $this->countBy($this->inPeriod($this->submissions($context), 'f.submitted_at', $previous), 'f.type');
        $b = $this->backlogCounts($context);

        return SituationPanel::kpis('voice_kpis', $this->title('voice_kpis'), $this->source('public_submissions'), [
            $this->kpi('complaints_received', $now['complaint'] ?? 0, ['previous' => $before['complaint'] ?? 0]),
            $this->kpi('tip_offs_received', $now['suggestion'] ?? 0, ['previous' => $before['suggestion'] ?? 0]),
            $this->kpi('feedback_received', $now['feedback'] ?? 0, ['previous' => $before['feedback'] ?? 0]),
            $this->kpi('complaints_open', $b['open_end'], ['previous' => $b['open_start']]),
            $this->kpi('unrouted_share', $b['unrouted_share'], ['unit' => '%']),
        ], $this->hint('voice_kpis'));
    }

    private function trendPanel(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $unit = SqlDateBucket::unitForDays((int) $period->from->diffInDays($period->to) + 1);
        $series = [];
        $categories = [];
        foreach (self::TYPES as $type) {
            $trend = $this->series($this->submissions($context, ServiceFeedbackType::from($type)), 'f.submitted_at', $period->from, $period->to, $unit);
            $categories = $trend['categories'];
            $series[] = ['key' => $type, 'label' => $this->value('submission_type', $type), 'values' => $trend['values']];
        }

        return $this->chart('voice_trend', 'line', $this->source('public_submissions'), $categories, $series, span: 2);
    }

    /** Types across, targets stacked: the two axes the inboxes keep apart. */
    private function targets(SituationContext $context): SituationPanel
    {
        $rows = $this->inPeriod($this->submissions($context), 'f.submitted_at', $context->period())
            ->selectRaw("f.type AS type, COALESCE(f.target_type, 'employee') AS target, COUNT(*) AS total")
            ->groupBy('type', 'target')
            ->get();
        $matrix = [];
        foreach ($rows as $row) {
            $matrix[(string) $row->type][(string) $row->target] = (int) $row->total;
        }
        $series = [];
        foreach (array_column(PublicFeedbackTargetType::cases(), 'value') as $target) {
            $series[] = ['key' => $target, 'label' => SituationLabel::key("situationRoom.values.target_type.{$target}"), 'values' => array_map(fn (string $t): int => $matrix[$t][$target] ?? 0, self::TYPES)];
        }

        return $this->chart('voice_targets', 'stacked', $this->source('public_submissions'), array_map(fn (string $t): array => $this->value('submission_type', $t), self::TYPES), $series);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $rows = $this->inPeriod($this->submissions($context), 'f.submitted_at', $context->period())
            ->selectRaw('f.organization_id AS organization_id, f.type AS type, COUNT(*) AS total')
            ->groupBy('f.organization_id', 'f.type')
            ->get();
        $matrix = [];
        foreach ($rows as $row) {
            $matrix[(string) $row->organization_id][(string) $row->type] = (int) $row->total;
        }
        $categories = [];
        $series = [];
        foreach (self::TYPES as $type) {
            $rolled = $this->rolledUp($context, array_map(fn (array $r): int => $r[$type] ?? 0, $matrix));
            $categories = $rolled['categories'];
            $series[] = ['key' => $type, 'label' => $this->value('submission_type', $type), 'values' => $rolled['values']];
        }

        return $this->chart('voice_by_organization', 'hbar', $this->source('public_submissions'), $categories, $series, span: 2);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $rows = $this->inPeriod($this->submissions($context), 'f.submitted_at', $context->period())
            ->selectRaw('f.type AS type, f.status AS status, COUNT(*) AS total')
            ->groupBy('type', 'status')
            ->get();
        $buckets = ['open' => PublicVoiceAnalytics::OPEN, 'resolved' => PublicVoiceAnalytics::RESOLVED, 'closed' => ['closed']];
        $matrix = [];
        foreach ($rows as $row) {
            foreach ($buckets as $bucket => $statuses) {
                if (in_array((string) $row->status, $statuses, true)) {
                    $matrix[(string) $row->type][$bucket] = ($matrix[(string) $row->type][$bucket] ?? 0) + (int) $row->total;
                }
            }
        }
        $series = [];
        foreach (array_keys($buckets) as $bucket) {
            $series[] = ['key' => $bucket, 'label' => SituationLabel::key("situationRoom.values.handling.{$bucket}"), 'values' => array_map(fn (string $t): int => $matrix[$t][$bucket] ?? 0, self::TYPES)];
        }

        return $this->chart('voice_status', 'stacked', $this->source('public_submissions'), array_map(fn (string $t): array => $this->value('submission_type', $t), self::TYPES), $series);
    }

    private function categories(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriod($this->submissions($context, ServiceFeedbackType::Complaint), 'f.submitted_at', $context->period()), 'COALESCE('.$this->idText('f.category_id').", '__none')");
        $top = $this->topWithOther($counts);
        $names = PublicFeedbackCategory::query()->whereIn('id', array_filter(array_keys($top), fn ($k): bool => ! str_starts_with((string) $k, '__')))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($c): string => (string) $c->id);
        $categories = array_map(fn ($key): array => match ((string) $key) {
            '__none' => SituationLabel::key('situationRoom.values.common.not_categorised'),
            '__other' => SituationLabel::key('situationRoom.values.common.other'),
            default => SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null),
        }, array_keys($top));

        return $this->chart('complaints_by_category', 'hbar', $this->source('public_submissions'), $categories, [
            ['key' => 'complaint', 'label' => $this->value('submission_type', 'complaint'), 'values' => array_values($top)],
        ]);
    }

    /** By the catalogue service the submission names; a service only described in words is not a link. */
    private function services(SituationContext $context): SituationPanel
    {
        $linked = $this->inPeriod($this->submissions($context, ServiceFeedbackType::Complaint), 'f.submitted_at', $context->period())
            ->where(fn (Builder $q) => $q->whereNotNull('f.position_service_id')->orWhereNotNull('f.service_id'));
        $counts = $this->countBy($linked, 'COALESCE(f.position_service_id, f.service_id)');
        if ($counts === []) {
            return $this->notice('complaints_by_service', $this->source('public_submissions'), 'SERVICE_LINKAGE_REQUIRED');
        }
        $top = $this->topWithOther($counts);
        $ids = array_filter(array_keys($top), fn ($k): bool => $k !== '__other');
        $names = PositionService::withTrashed()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($s): string => (string) $s->id)
            ->union(Service::withTrashed()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($s): string => (string) $s->id));
        $categories = array_map(fn ($key): array => $key === '__other'
            ? SituationLabel::key('situationRoom.values.common.other')
            : SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null), array_keys($top));

        return $this->chart('complaints_by_service', 'hbar', $this->source('services'), $categories, [
            ['key' => 'complaint', 'label' => $this->value('submission_type', 'complaint'), 'values' => array_values($top)],
        ]);
    }

    private function backlog(SituationContext $context): SituationPanel
    {
        $b = $this->backlogCounts($context);

        return SituationPanel::kpis('complaints_backlog', $this->title('complaints_backlog'), $this->source('public_submissions'), [
            $this->kpi('open_at_period_start', $b['open_start']),
            $this->kpi('open_at_period_end', $b['open_end']),
            $this->kpi('backlog_change', Baseline::changePercent($b['open_end'], $b['open_start']), ['unit' => '%']),
            $this->kpi('unrouted', $b['unrouted']),
            $this->kpi('unrouted_share', $b['unrouted_share'], ['unit' => '%']),
        ], $this->hint('complaints_backlog'));
    }

    /**
     * Complaints open at the period's two ends (submitted by then, with no
     * outcome recorded by then), and the routing failures among the period's
     * routable submissions (tip-offs and complaints; feedback is not routed).
     *
     * @return array{open_start: int, open_end: int, unrouted: int, routable: int, unrouted_share: ?float}
     */
    public function backlogCounts(SituationContext $context): array
    {
        $period = $context->period();
        $openAt = fn (CarbonImmutable $at): int => (clone $this->submissions($context, ServiceFeedbackType::Complaint))
            ->where('f.submitted_at', '<=', $at->toDateTimeString())
            ->where(fn (Builder $q) => $q->whereRaw('COALESCE(f.resolved_at, f.closed_at) IS NULL')->orWhereRaw('COALESCE(f.resolved_at, f.closed_at) > ?', [$at->toDateTimeString()]))
            ->count();
        $routable = $this->inPeriod($this->submissions($context), 'f.submitted_at', $period)->whereIn('f.type', [ServiceFeedbackType::Suggestion->value, ServiceFeedbackType::Complaint->value]);
        $routableCount = (clone $routable)->count();
        $unrouted = (clone $routable)->where('f.status', ServiceFeedbackStatus::Unrouted->value)->count();

        return [
            'open_start' => $openAt($period->from->subMicrosecond()),
            'open_end' => $openAt($period->to),
            'unrouted' => $unrouted,
            'routable' => $routableCount,
            'unrouted_share' => $this->percent($unrouted, $routableCount),
        ];
    }

    /**
     * The last seven days of the period against the weekly counts of the
     * baseline window before them. The window is stretched to at least five
     * weeks because the robust baseline needs five points to say anything.
     *
     * @return array{current: int, history: list<int>, week_from: string, week_to: string}
     */
    private function weeklyComplaints(SituationContext $context): array
    {
        $to = $context->period()->to;
        $weekFrom = $to->subDays(6)->startOfDay();
        $weeks = max(Baseline::MIN_POINTS, (int) ceil($this->intelligence->baselineWindowDays() / 7));
        $historyFrom = $weekFrom->subWeeks($weeks);
        $historyTo = $weekFrom->subMicrosecond();
        $history = [];
        $perDay = $this->series($this->submissions($context, ServiceFeedbackType::Complaint), 'f.submitted_at', $historyFrom, $historyTo, SqlDateBucket::DAY);
        // Re-bucket days into windows of seven aligned to the current week, so every point is a full week.
        foreach (array_chunk($perDay['values'], 7) as $chunk) {
            if (count($chunk) === 7) {
                $history[] = array_sum($chunk);
            }
        }
        $current = (clone $this->submissions($context, ServiceFeedbackType::Complaint))->whereBetween('f.submitted_at', [$weekFrom->toDateTimeString(), $to->toDateTimeString()])->count();

        return ['current' => $current, 'history' => $history, 'week_from' => $weekFrom->toDateString(), 'week_to' => $to->toDateString()];
    }

    /**
     * A complaint drop of 40% or more. The technical domain is asked whether
     * the public-feedback routes failed in the same week; when it says so
     * the insight says the drop MAY be influenced by failures, and when it
     * cannot say, the insight makes no technical claim at all.
     *
     * @param  array{current: int, history: list<int>, week_from: string, week_to: string}  $weekly
     */
    private function complaintDrop(array $weekly, float $median): Insight
    {
        $from = CarbonImmutable::parse($weekly['week_from'])->startOfDay();
        $to = CarbonImmutable::parse($weekly['week_to'])->endOfDay();
        $technical = $this->technical->failuresFor(RouteGroups::PUBLIC_FEEDBACK, $from, $to);
        $params = ['current' => $weekly['current'], 'baseline' => round($median, 1), 'percent' => Baseline::changePercent($weekly['current'], $median) ?? 0];
        $evidence = [
            $this->evidence('window', $weekly['week_from'].' – '.$weekly['week_to']),
            $this->evidence('count', $weekly['current']),
            $this->evidence('baseline_median_weekly', round($median, 1)),
        ];
        $failed = $technical['known'] && (($technical['failure_rate_percent'] ?? 0) >= self::FAILURE_RATE_PERCENT || $technical['incidents'] > 0);

        if ($failed) {
            $evidence[] = $this->evidence('route', RouteGroups::PUBLIC_FEEDBACK);
            $evidence[] = $this->evidence('failure_rate', ($technical['failure_rate_percent'] ?? 0).' %');
            $evidence[] = $this->evidence('incidents', $technical['incidents']);

            return $this->insight('complaint_drop_with_failures', 'medium', $params + ['failure_rate' => $technical['failure_rate_percent'] ?? 0, 'incidents' => $technical['incidents']], $evidence,
                $this->change($weekly['current'], $median), confidence: 'medium', source: $this->source('public_submissions'), metric: 'complaints_received');
        }

        return $this->insight('complaint_drop', 'info', $params, $evidence, $this->change($weekly['current'], $median), source: $this->source('public_submissions'), metric: 'complaints_received');
    }
}
