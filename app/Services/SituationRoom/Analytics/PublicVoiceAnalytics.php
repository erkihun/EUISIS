<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\PublicFeedbackTargetType;
use App\Enums\PublicIntakeChannel;
use App\Enums\PublicTipType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\Employee;
use App\Models\PositionService;
use App\Models\Service;
use App\Services\ServiceFeedback\PublicFeedbackAnswerStatistics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationSql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Community voice — public Feedback, Tip-offs (stored as `suggestion`) and
 * Complaints — as counts of what was received and how it is being handled.
 *
 * A count is a volume of reports, never a finding: nothing here orders
 * organizations or people by how much was said about them, and the words
 * on screen say "received", not "violations". A public complaint is not an
 * employee grievance, a disciplinary case or an appeal; those live in their
 * own category under their own grant.
 *
 * Never in a payload: the submitter's name, contact, address or narrative,
 * or any evidence. Employee names appear only in the employee breakdown, and
 * only with situation_room.view_employee_targeted and
 * service_feedback.view_employee_target, inside one focused organization, in
 * name order.
 */
abstract class PublicVoiceAnalytics extends SituationAnalytics
{
    public const SOURCE = 'situationRoom.sources.public_submissions';

    /** Statuses still waiting for an outcome. */
    public const OPEN = ['pending', 'unrouted', 'assigned', 'under_review', 'reviewed'];

    /** Statuses that record an outcome, whatever the type calls it. */
    public const RESOLVED = ['resolved', 'accepted', 'not_adopted'];

    private const BREAKDOWN_PAGE_SIZE = 25;

    abstract protected function type(): ServiceFeedbackType;

    /** The panel-key prefix: complaints, feedback or tip_offs. */
    abstract protected function prefix(): string;

    public function panels(SituationContext $context): array
    {
        $p = $this->prefix();
        $panels = [
            "{$p}_kpis" => fn (): SituationPanel => $this->kpis($context),
            "{$p}_trend" => fn (): SituationPanel => $this->trendPanel($context),
            "{$p}_by_organization" => fn (): SituationPanel => $this->byOrganization($context),
            "{$p}_status" => fn (): SituationPanel => $this->status($context),
        ];

        $panels += match ($this->type()) {
            ServiceFeedbackType::Suggestion => ["{$p}_tip_type" => fn (): SituationPanel => $this->tipTypes($context)],
            ServiceFeedbackType::Feedback => ["{$p}_ratings" => fn (): SituationPanel => $this->ratings($context)],
            ServiceFeedbackType::Complaint => [],
        };

        return $panels + [
            "{$p}_target" => fn (): SituationPanel => $this->targets($context),
            "{$p}_service" => fn (): SituationPanel => $this->services($context),
            "{$p}_question_ratings" => fn (): SituationPanel => $this->questionRatings($context),
            "{$p}_question_choices" => fn (): SituationPanel => $this->questionChoices($context),
            "{$p}_channel" => fn (): SituationPanel => $this->channels($context),
            "{$p}_employees" => fn (): SituationPanel => $this->employees($context),
        ];
    }

    /**
     * Submissions of this type in scope, moderated-out entries excluded,
     * narrowed by the view's filters. Period is applied by each panel.
     */
    public function base(SituationContext $context): Builder
    {
        $query = DB::table('employee_service_feedback as f')
            ->where('f.type', $this->type()->value)
            ->where('f.status', '!=', ServiceFeedbackStatus::Hidden->value);

        $context->scope->apply($query, 'f.organization_id');
        $filters = $context->filters;

        if (($target = $filters->get('target_type')) !== null) {
            $target === PublicFeedbackTargetType::Employee->value
                ? $query->where(fn ($q) => $q->where('f.target_type', $target)->orWhereNull('f.target_type'))
                : $query->where('f.target_type', $target);
        }
        foreach (['status' => 'f.status', 'position_service_id' => 'f.position_service_id', 'organization_unit_id' => 'f.organization_unit_id'] as $key => $column) {
            if (($value = $filters->get($key)) !== null) {
                $query->where($column, $value);
            }
        }
        if ($this->type() === ServiceFeedbackType::Suggestion && ($tip = $filters->get('tip_type')) !== null) {
            $query->where('f.tip_type', $tip);
        }

        return $query;
    }

    private function inPeriodBase(SituationContext $context): Builder
    {
        return $this->base($context)->whereBetween('f.submitted_at', [$context->period()->from->toDateTimeString(), $context->period()->to->toDateTimeString()]);
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $p = $this->prefix();
        $period = $context->period();
        $previous = $period->previous();
        $received = (clone $this->inPeriodBase($context))->count();
        $before = (clone $this->base($context))->whereBetween('f.submitted_at', [$previous->from->toDateTimeString(), $previous->to->toDateTimeString()])->count();
        $open = (clone $this->base($context))->whereIn('f.status', self::OPEN)->count();
        $outcome = 'COALESCE(f.resolved_at, f.closed_at)';
        $resolved = (clone $this->base($context))->whereNotNull(DB::raw($outcome))
            ->whereBetween(DB::raw($outcome), [$period->from->toDateTimeString(), $period->to->toDateTimeString()]);
        $resolvedCount = (clone $resolved)->count();
        $days = (clone $resolved)->selectRaw('AVG('.SituationSql::daysBetween('f.submitted_at', $outcome).') AS d')->value('d');

        $items = [
            $this->kpi("{$p}_received", $received, ['previous' => $before]),
            $this->kpi("{$p}_open", $open),
            $this->kpi("{$p}_resolved", $resolvedCount),
            $this->kpi('average_days_to_outcome', $days === null ? null : round((float) $days, 1), ['unit' => 'days']),
        ];

        if ($this->type() === ServiceFeedbackType::Feedback) {
            $rating = (clone $this->inPeriodBase($context))->whereNotNull('f.rating')->avg('f.rating');
            $items[] = $this->kpi('average_rating', $rating === null ? null : round((float) $rating, 2), ['unit' => '/5']);
        }
        if ($this->type() === ServiceFeedbackType::Suggestion) {
            $byTip = $this->countBy($this->inPeriodBase($context), 'f.tip_type');
            $items[] = $this->kpi('tip_corruption', $byTip[PublicTipType::Corruption->value] ?? 0);
            $items[] = $this->kpi('tip_improper_practice', $byTip[PublicTipType::ImproperPractice->value] ?? 0);
        }

        return SituationPanel::kpis("{$p}_kpis", $this->title("{$p}_kpis"), self::SOURCE, $items);
    }

    private function trendPanel(SituationContext $context): SituationPanel
    {
        $p = $this->prefix();
        $trend = $this->trend($this->base($context), 'f.submitted_at', $context->period());

        return $this->chart("{$p}_trend", 'line', self::SOURCE, $trend['categories'], [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => $trend['values']],
        ], span: 2);
    }

    private function byOrganization(SituationContext $context): SituationPanel
    {
        $p = $this->prefix();
        $rows = (clone $this->inPeriodBase($context))
            ->selectRaw('f.organization_id AS organization_id, COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN f.status IN ('".implode("','", self::OPEN)."') THEN 1 ELSE 0 END) AS open_count")
            ->groupBy('f.organization_id')
            ->get();
        $received = $open = [];
        foreach ($rows as $row) {
            $received[(string) $row->organization_id] = (int) $row->total;
            $open[(string) $row->organization_id] = (int) $row->open_count;
        }
        $total = $this->rolledUp($context, $received);
        $stillOpen = $this->rolledUp($context, $open);

        return $this->chart("{$p}_by_organization", 'hbar', self::SOURCE, $total['categories'], [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => $total['values']],
            ['key' => 'open', 'label' => SituationLabel::key('situationRoom.series.still_open'), 'values' => $stillOpen['values']],
        ], span: 2);
    }

    private function status(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriodBase($context), 'f.status');
        $buckets = ['open' => self::OPEN, 'resolved' => self::RESOLVED, 'closed' => ['closed']];
        $values = array_map(fn (array $statuses): int => array_sum(array_map(fn (string $s): int => $counts[$s] ?? 0, $statuses)), $buckets);

        return $this->chart("{$this->prefix()}_status", 'donut', self::SOURCE, $this->valueLabels('handling', array_keys($buckets)), [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => array_values($values)],
        ]);
    }

    private function tipTypes(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriodBase($context), "COALESCE(f.tip_type, '".PublicTipType::UNCLASSIFIED_LEGACY."')");
        $buckets = [...PublicTipType::values(), PublicTipType::UNCLASSIFIED_LEGACY];

        return $this->chart('tip_offs_tip_type', 'donut', self::SOURCE, $this->valueLabels('tip_type', $buckets), [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function ratings(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy((clone $this->inPeriodBase($context))->whereNotNull('f.rating'), 'f.rating');
        $buckets = ['1', '2', '3', '4', '5'];

        return $this->chart('feedback_ratings', 'bar', self::SOURCE, array_map(fn (string $b): string => $b.' ★', $buckets), [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    private function targets(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriodBase($context), "COALESCE(f.target_type, 'employee')");
        $buckets = array_column(PublicFeedbackTargetType::cases(), 'value');

        return $this->chart("{$this->prefix()}_target", 'donut', self::SOURCE, $this->valueLabels('target_type', $buckets), [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    /**
     * By the service named on the submission: the position service of an
     * employee-QR submission, or the institution catalog service chosen on the
     * organization feedback form. A service the visitor only described in
     * words is not linked and is not counted here. When nothing in the slice
     * names a service the panel reports SERVICE_LINKAGE_REQUIRED.
     */
    private function services(SituationContext $context): SituationPanel
    {
        $p = $this->prefix();
        $linked = (clone $this->inPeriodBase($context))->where(fn (Builder $query) => $query->whereNotNull('f.position_service_id')->orWhereNotNull('f.service_id'));
        $counts = $this->countBy($linked, 'COALESCE(f.position_service_id, f.service_id)');

        if ($counts === []) {
            return $this->notice("{$p}_service", self::SOURCE, 'SERVICE_LINKAGE_REQUIRED');
        }

        $top = $this->topWithOther($counts);
        $ids = array_filter(array_keys($top), fn ($k): bool => $k !== '__other');
        $names = PositionService::withTrashed()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($s): string => (string) $s->id)
            ->union(Service::withTrashed()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($s): string => (string) $s->id));
        $categories = array_map(fn ($key): array => $key === '__other'
            ? SituationLabel::key('situationRoom.values.common.other')
            : SituationLabel::names($names[$key]->name_en ?? null, $names[$key]->name_am ?? null), array_keys($top));

        return $this->chart("{$p}_service", 'hbar', 'situationRoom.sources.position_services', $categories, [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => array_values($top)],
        ]);
    }

    /**
     * The organization feedback form's rating questions: how many answers
     * fell on each point of the five-point scale, per question, for the
     * submissions this view counts. A distribution only — the scale's
     * points are labels, so no per-question average is drawn here.
     */
    private function questionRatings(SituationContext $context): SituationPanel
    {
        $key = "{$this->prefix()}_question_ratings";
        $statistics = app(PublicFeedbackAnswerStatistics::class);
        $summary = $statistics->ratingSummaryOf($this->inPeriodBase($context));

        if ($summary === []) {
            return $this->notice($key, self::SOURCE, 'NO_DATA', 2);
        }

        $labels = $statistics->labels(array_column($summary, 'code'));
        usort($summary, fn (array $a, array $b): int => [$labels[$a['code']]['sort'] ?? PHP_INT_MAX, $a['code']] <=> [$labels[$b['code']]['sort'] ?? PHP_INT_MAX, $b['code']]);
        $series = [];

        foreach ([1, 2, 3, 4, 5] as $point) {
            $series[] = [
                'key' => 'rating_'.$point,
                'label' => SituationLabel::key("situationRoom.values.satisfaction.{$point}"),
                'values' => array_map(fn (array $question): int => $question['distribution'][$point], $summary),
            ];
        }

        return $this->chart($key, 'stacked', self::SOURCE, array_map(
            fn (array $question): array => SituationLabel::names($labels[$question['code']]['en'] ?? $question['code'], $labels[$question['code']]['am'] ?? null),
            $summary,
        ), $series, span: 2, palette: 'diverging');
    }

    /**
     * The organization feedback form's choice and yes/no questions: per
     * question, how many answered it and how many chose each choice. Free-text
     * answers are never aggregated or shown here.
     */
    private function questionChoices(SituationContext $context): SituationPanel
    {
        $key = "{$this->prefix()}_question_choices";
        $statistics = app(PublicFeedbackAnswerStatistics::class);
        $summary = $statistics->choiceSummaryOf($this->inPeriodBase($context));
        $labels = $statistics->labels(array_column($summary, 'code'));
        usort($summary, fn (array $a, array $b): int => [$labels[$a['code']]['sort'] ?? PHP_INT_MAX, $a['code']] <=> [$labels[$b['code']]['sort'] ?? PHP_INT_MAX, $b['code']]);
        $rows = [];

        foreach ($summary as $question) {
            $label = $labels[$question['code']] ?? ['en' => $question['code'], 'am' => null, 'options' => []];
            $choices = $question['choices'];
            // Yes before no; options in the questionnaire's order.
            uksort($choices, fn (string $a, string $b): int => [$label['options'][$a]['sort'] ?? ($a === 'yes' ? 0 : 1), $a] <=> [$label['options'][$b]['sort'] ?? ($b === 'yes' ? 0 : 1), $b]);

            foreach ($choices as $choice => $total) {
                $rows[] = [
                    'question' => SituationLabel::names($label['en'], $label['am']),
                    'answer' => in_array($choice, ['yes', 'no'], true) && ! isset($label['options'][$choice])
                        ? SituationLabel::key("situationRoom.values.yes_no.{$choice}")
                        : SituationLabel::names($label['options'][$choice]['en'] ?? $choice, $label['options'][$choice]['am'] ?? null),
                    'total' => $total,
                    'share' => $this->percent($total, $question['answered']),
                ];
            }
        }

        return SituationPanel::table($key, $this->title($key), $this->hint($key), self::SOURCE, [
            ['key' => 'question', 'label' => SituationLabel::key('situationRoom.columns.question'), 'type' => 'label'],
            ['key' => 'answer', 'label' => SituationLabel::key('situationRoom.columns.answer'), 'type' => 'label'],
            ['key' => 'total', 'label' => SituationLabel::key('situationRoom.columns.chosen'), 'type' => 'number'],
            ['key' => 'share', 'label' => SituationLabel::key('situationRoom.columns.share_of_answers'), 'type' => 'percent'],
        ], $rows);
    }

    private function channels(SituationContext $context): SituationPanel
    {
        $counts = $this->countBy($this->inPeriodBase($context), "COALESCE(f.intake_channel, '".PublicIntakeChannel::WebQr->value."')");
        $buckets = PublicIntakeChannel::values();

        return $this->chart("{$this->prefix()}_channel", 'donut', self::SOURCE, $this->valueLabels('channel', $buckets), [
            ['key' => 'received', 'label' => SituationLabel::key('situationRoom.series.received'), 'values' => array_map(fn (string $b): int => $counts[$b] ?? 0, $buckets)],
        ]);
    }

    /**
     * Employee-targeted submissions inside ONE focused organization, by
     * employee name, paginated. Requires the explicit breakdown grant; the
     * viewer's own record is never listed to them.
     */
    private function employees(SituationContext $context): SituationPanel
    {
        $key = "{$this->prefix()}_employees";

        if (! $context->can(SituationPermissions::EMPLOYEE_BREAKDOWN) || ! $context->can(SituationPermissions::EMPLOYEE_TARGET)) {
            return $this->notice($key, self::SOURCE, 'NO_PERMISSION', 2);
        }
        if ($context->scope->focusOrganizationId === null) {
            return $this->notice($key, self::SOURCE, 'SELECT_ORGANIZATION', 2);
        }

        $query = (clone $this->inPeriodBase($context))
            ->whereNotNull('f.employee_id')
            ->when($context->user->employee_id !== null, fn ($q) => $q->where('f.employee_id', '!=', $context->user->employee_id))
            ->join('employees as e', 'e.id', '=', 'f.employee_id')
            ->selectRaw('f.employee_id AS employee_id, e.full_name AS full_name, e.employee_number AS employee_number, COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN f.status IN ('".implode("','", self::OPEN)."') THEN 1 ELSE 0 END) AS open_count")
            ->groupBy('f.employee_id', 'e.full_name', 'e.employee_number')
            ->orderBy('e.full_name');
        $total = (int) DB::query()->fromSub((clone $query), 't')->count();
        $page = $context->filters->page();
        $rows = $query->forPage($page, self::BREAKDOWN_PAGE_SIZE)->get();

        return SituationPanel::table($key, $this->title($key), $this->hint($key), self::SOURCE, [
            ['key' => 'full_name', 'label' => SituationLabel::key('situationRoom.columns.employee'), 'type' => 'text'],
            ['key' => 'employee_number', 'label' => SituationLabel::key('situationRoom.columns.employee_number'), 'type' => 'text'],
            ['key' => 'total', 'label' => SituationLabel::key('situationRoom.series.received'), 'type' => 'number'],
            ['key' => 'open', 'label' => SituationLabel::key('situationRoom.series.still_open'), 'type' => 'number'],
        ], $rows->map(fn ($row): array => [
            'full_name' => (string) $row->full_name,
            'employee_number' => (string) $row->employee_number,
            'total' => (int) $row->total,
            'open' => (int) $row->open_count,
        ])->all(), ['page' => $page, 'per_page' => self::BREAKDOWN_PAGE_SIZE, 'total' => $total]);
    }
}
