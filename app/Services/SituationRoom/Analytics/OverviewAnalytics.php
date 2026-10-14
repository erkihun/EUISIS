<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\FieldWorkStatus;
use App\Enums\SituationActionStatus;
use App\Models\SituationAction;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Executive Overview: one headline per area the viewer may see, each linked
 * to its category, from the same queries the categories use — the overview
 * can never disagree with the page it links to.
 */
final class OverviewAnalytics extends SituationAnalytics
{
    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly ComplaintsAnalytics $complaints,
        private readonly TipOffAnalytics $tipOffs,
        private readonly FeedbackAnalytics $feedback,
        private readonly CriticalAttentionAnalytics $attention,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        $panels = ['overview_kpis' => fn (): SituationPanel => $this->kpis($context)];

        if ($context->can(SituationPermissions::PUBLIC_VOICE)) {
            $panels['overview_public_voice_trend'] = fn (): SituationPanel => $this->publicVoiceTrend($context);
        }
        if ($context->can(SituationPermissions::WORKFORCE)) {
            $panels['overview_headcount'] = fn (): SituationPanel => $this->headcount($context);
        }
        if ($context->can(SituationPermissions::ACTIONS_VIEW)) {
            $panels['overview_actions'] = fn (): SituationPanel => $this->actions($context);
        }

        return $panels;
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $items = [];
        $link = fn (SituationCategory $category): string => route('situation-room.category', ['category' => $category->value] + $context->filters->query());
        $period = $context->period();

        if ($context->can(SituationPermissions::WORKFORCE)) {
            $capacity = WorkforceQueries::capacityByOrganization($context);
            $items[] = $this->kpi('headcount', WorkforceQueries::headcount($context), ['href' => $link(SituationCategory::Workforce)]);
            $items[] = $this->kpi('vacancy_rate', $this->percent(array_sum(array_column($capacity, 'vacant')), array_sum(array_column($capacity, 'capacity'))), ['unit' => '%', 'href' => $link(SituationCategory::Workforce)]);
        }

        if ($context->can(SituationPermissions::PUBLIC_VOICE)) {
            $inPeriod = fn (PublicVoiceAnalytics $a) => $this->inPeriod($a->base($context), 'f.submitted_at', $period);
            $items[] = $this->kpi('complaints_received', (clone $inPeriod($this->complaints))->count(), ['href' => $link(SituationCategory::Complaints)]);
            $items[] = $this->kpi('tip_offs_received', (clone $inPeriod($this->tipOffs))->count(), ['href' => $link(SituationCategory::TipOffs)]);
            $rating = (clone $inPeriod($this->feedback))->whereNotNull('f.rating')->avg('f.rating');
            $items[] = $this->kpi('average_rating', $rating === null ? null : round((float) $rating, 2), ['unit' => '/5', 'href' => $link(SituationCategory::Feedback)]);
        }

        if ($context->can(SituationPermissions::OPERATIONS)) {
            $people = $this->inPeriod(DailyOperationsAnalytics::base($context), 'l.activity_date', $period, true)->where('l.status', '!=', 'draft')->distinct()->count('l.employee_id');
            $items[] = $this->kpi('reporting_rate', $this->percent($people, WorkforceQueries::headcount($context)), ['unit' => '%', 'href' => $link(SituationCategory::DailyOperations)]);
            $items[] = $this->kpi('in_field_now', FieldWorkAnalytics::base($context)->where('r.status', FieldWorkStatus::InField->value)->count(), ['href' => $link(SituationCategory::FieldWork)]);
        }

        $attention = 0;
        $configured = false;
        foreach (CriticalAttentionAnalytics::rules() as $rule => $meta) {
            if ($context->can($meta['permission']) && ($counts = $this->attention->counts($context, $rule)) !== null) {
                $configured = true;
                $attention += array_sum($counts);
            }
        }
        $items[] = $this->kpi('attention_items', $configured ? $attention : null, [
            'state' => $configured ? ($attention > 0 ? 'attention' : 'clear') : 'not_configured',
            'href' => $link(SituationCategory::CriticalAttention),
        ]);

        if ($context->can(SituationPermissions::ACTIONS_VIEW)) {
            $items[] = $this->kpi('actions_open', $this->actionQuery($context)->whereIn('status', SituationActionStatus::openValues())->count(), ['href' => route('situation-room.actions.index')]);
        }

        return SituationPanel::kpis('overview_kpis', $this->title('overview_kpis'), 'situationRoom.sources.overview', $items);
    }

    private function publicVoiceTrend(SituationContext $context): SituationPanel
    {
        $series = [];
        $categories = [];
        foreach (['complaints' => $this->complaints, 'tip_offs' => $this->tipOffs, 'feedback' => $this->feedback] as $key => $analytics) {
            $trend = $this->trend($analytics->base($context), 'f.submitted_at', $context->period());
            $categories = $trend['categories'];
            $series[] = ['key' => $key, 'label' => SituationLabel::key("situationRoom.categories.{$key}.title"), 'values' => $trend['values']];
        }

        return $this->chart('overview_public_voice_trend', 'line', PublicVoiceAnalytics::SOURCE, $categories, $series, span: 2);
    }

    private function headcount(SituationContext $context): SituationPanel
    {
        $rolled = $this->rolledUp($context, WorkforceQueries::headcountByOrganization($context));

        return $this->chart('overview_headcount', 'hbar', WorkforceAnalytics::SOURCE, $rolled['categories'], [
            ['key' => 'headcount', 'label' => SituationLabel::key('situationRoom.kpi.headcount'), 'values' => $rolled['values']],
        ]);
    }

    /** Open actions in scope, soonest due first — a work list, not a ranking. */
    private function actions(SituationContext $context): SituationPanel
    {
        $rows = $this->actionQuery($context)
            ->with('organization:id,name_en,name_am')
            ->whereIn('status', SituationActionStatus::openValues())
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        return SituationPanel::table('overview_actions', $this->title('overview_actions'), $this->hint('overview_actions'), 'situationRoom.sources.actions', [
            ['key' => 'reference_number', 'label' => SituationLabel::key('situationRoom.columns.reference'), 'type' => 'text'],
            ['key' => 'title', 'label' => SituationLabel::key('situationRoom.columns.title'), 'type' => 'text'],
            ['key' => 'organization', 'label' => SituationLabel::key('situationRoom.columns.organization'), 'type' => 'label'],
            ['key' => 'priority', 'label' => SituationLabel::key('situationRoom.columns.priority'), 'type' => 'label'],
            ['key' => 'due_date', 'label' => SituationLabel::key('situationRoom.columns.due_date'), 'type' => 'date'],
        ], $rows->map(fn (SituationAction $a): array => [
            'reference_number' => $a->reference_number,
            'title' => $a->title,
            'organization' => SituationLabel::names($a->organization?->name_en, $a->organization?->name_am),
            'priority' => SituationLabel::key('situationRoom.values.priority.'.$a->priority->value),
            'due_date' => $a->due_date?->toDateString(),
        ])->all(), href: route('situation-room.actions.index'), span: 1);
    }

    /** @return Builder<SituationAction> */
    private function actionQuery(SituationContext $context): Builder
    {
        $query = SituationAction::query();
        $context->scope->apply($query, 'organization_id');

        return $query;
    }
}
