<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Enums\TransferStatus;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationPeriod;
use App\Services\SituationRoom\SituationPermissions;
use App\Services\SituationRoom\SituationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FieldWork\Contracts\Data\FieldWorkStatus;

/**
 * Trends & Comparison: twelve months of the main volumes, this period
 * against the one before, and the roll-up rows side by side. Side by side
 * means in name order, never sorted by a value: it is there to read across,
 * not to rank. Headcount history is not snapshotted, so a workforce trend is
 * reported as NEEDS_DATA rather than reconstructed.
 */
final class TrendsAnalytics extends SituationAnalytics
{
    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly ComplaintsAnalytics $complaints,
        private readonly TipOffAnalytics $tipOffs,
        private readonly FeedbackAnalytics $feedback,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        $panels = ['trends_comparison' => fn (): SituationPanel => $this->comparison($context)];

        if ($context->can(SituationPermissions::PUBLIC_VOICE)) {
            $panels['trends_public_voice'] = fn (): SituationPanel => $this->publicVoice($context);
        }
        if ($context->can(SituationPermissions::OPERATIONS)) {
            $panels['trends_operations'] = fn (): SituationPanel => $this->operations($context);
        }
        if ($context->can(SituationPermissions::WORKFORCE)) {
            $panels['trends_workforce'] = fn (): SituationPanel => $this->notice('trends_workforce', WorkforceAnalytics::SOURCE, 'NEEDS_DATA');
        }

        return $panels + ['trends_organizations' => fn (): SituationPanel => $this->organizations($context)];
    }

    private function twelveMonths(): SituationPeriod
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));

        return new SituationPeriod('custom', $now->subMonthsNoOverflow(11)->startOfMonth(), $now->endOfDay());
    }

    private function publicVoice(SituationContext $context): SituationPanel
    {
        $period = $this->twelveMonths();
        $series = [];
        $categories = [];
        foreach (['complaints' => $this->complaints, 'tip_offs' => $this->tipOffs, 'feedback' => $this->feedback] as $key => $analytics) {
            $trend = $this->trend($analytics->base($context), 'f.submitted_at', $period, granularity: 'month');
            $categories = $trend['categories'];
            $series[] = ['key' => $key, 'label' => SituationLabel::key("situationRoom.categories.{$key}.title"), 'values' => $trend['values']];
        }

        return $this->chart('trends_public_voice', 'line', PublicVoiceAnalytics::SOURCE, $categories, $series, span: 2);
    }

    private function operations(SituationContext $context): SituationPanel
    {
        $period = $this->twelveMonths();
        $days = $this->trend(DailyOperationsAnalytics::base($context)->where('l.status', '!=', 'draft'), 'l.activity_date', $period, true, 'month');
        $field = $this->trend(FieldWorkAnalytics::base($context)->where('r.status', '!=', FieldWorkStatus::Draft->value), 'r.starts_at', $period, granularity: 'month');

        return $this->chart('trends_operations', 'line', DailyOperationsAnalytics::SOURCE, $days['categories'], [
            ['key' => 'days_reported', 'label' => SituationLabel::key('situationRoom.kpi.days_reported'), 'values' => $days['values']],
            ['key' => 'field_started', 'label' => SituationLabel::key('situationRoom.kpi.field_started'), 'values' => $field['values']],
        ], span: 2);
    }

    /** This period against the previous one of the same length. */
    private function comparison(SituationContext $context): SituationPanel
    {
        $current = $context->period();
        $previous = $current->previous();
        $metrics = [];

        if ($context->can(SituationPermissions::PUBLIC_VOICE)) {
            foreach (['complaints_received' => $this->complaints, 'tip_offs_received' => $this->tipOffs, 'feedback_received' => $this->feedback] as $key => $analytics) {
                $metrics[$key] = fn (SituationPeriod $p): int => $this->inPeriod($analytics->base($context), 'f.submitted_at', $p)->count();
            }
        }
        if ($context->can(SituationPermissions::OPERATIONS)) {
            $metrics['days_reported'] = fn (SituationPeriod $p): int => $this->inPeriod(DailyOperationsAnalytics::base($context), 'l.activity_date', $p, true)->where('l.status', '!=', 'draft')->count();
            $metrics['field_started'] = fn (SituationPeriod $p): int => $this->inPeriod(FieldWorkAnalytics::base($context), 'r.starts_at', $p)->where('r.status', '!=', FieldWorkStatus::Draft->value)->count();
        }
        if ($context->can(SituationPermissions::WORKFORCE)) {
            $metrics['transfers_completed'] = function (SituationPeriod $p) use ($context): int {
                $query = DB::table('employee_transfers as t')->where('t.status', TransferStatus::Completed->value);
                $ids = $context->scope->organizationIds;
                if ($ids !== null) {
                    $ids === [] ? $query->whereRaw('1 = 0') : $query->where(fn ($q) => $q->whereIn('t.from_organization_id', $ids)->orWhereIn('t.to_organization_id', $ids));
                }

                return $this->inPeriod($query, 't.completed_at', $p)->count();
            };
        }

        $rows = [];
        foreach ($metrics as $key => $measure) {
            $now = $measure($current);
            $before = $measure($previous);
            $rows[] = [
                'metric' => SituationLabel::key("situationRoom.kpi.{$key}"),
                'current' => $now,
                'previous' => $before,
                'change' => $before > 0 ? round(100 * ($now - $before) / $before, 1) : null,
            ];
        }

        return SituationPanel::table('trends_comparison', $this->title('trends_comparison'), $this->hint('trends_comparison'), 'situationRoom.sources.overview', [
            ['key' => 'metric', 'label' => SituationLabel::key('situationRoom.columns.metric'), 'type' => 'label'],
            ['key' => 'current', 'label' => SituationLabel::key('situationRoom.columns.this_period'), 'type' => 'number'],
            ['key' => 'previous', 'label' => SituationLabel::key('situationRoom.columns.previous_period'), 'type' => 'number'],
            ['key' => 'change', 'label' => SituationLabel::key('situationRoom.columns.change'), 'type' => 'percent'],
        ], $rows, span: 1);
    }

    /** Roll-up rows side by side, in name order; each column only with its grant. */
    private function organizations(SituationContext $context): SituationPanel
    {
        $period = $context->period();
        $columns = [];

        if ($context->can(SituationPermissions::WORKFORCE)) {
            $capacity = WorkforceQueries::capacityByOrganization($context);
            $columns['headcount'] = $this->rolledUp($context, WorkforceQueries::headcountByOrganization($context));
            $columns['vacant'] = $this->rolledUp($context, array_map(fn (array $r): int => $r['vacant'], $capacity));
        }
        if ($context->can(SituationPermissions::PUBLIC_VOICE)) {
            foreach (['complaints_received' => $this->complaints, 'tip_offs_received' => $this->tipOffs, 'feedback_received' => $this->feedback] as $key => $analytics) {
                $columns[$key] = $this->rolledUp($context, $this->countBy($this->inPeriod($analytics->base($context), 'f.submitted_at', $period), 'f.organization_id'));
            }
        }
        if ($context->can(SituationPermissions::OPERATIONS)) {
            $columns['people_reporting'] = $this->rolledUp($context, $this->inPeriod(DailyOperationsAnalytics::base($context), 'l.activity_date', $period, true)
                ->where('l.status', '!=', 'draft')
                ->selectRaw('l.organization_id AS organization_id, COUNT(DISTINCT l.employee_id) AS total')
                ->groupBy('l.organization_id')->pluck('total', 'organization_id')->map(fn ($v): int => (int) $v)->all());
        }

        if ($columns === []) {
            return $this->notice('trends_organizations', 'situationRoom.sources.overview', 'NO_PERMISSION', 3);
        }

        $order = reset($columns);
        $rows = [];
        foreach ($order['ids'] as $i => $id) {
            $row = ['organization' => $order['categories'][$i]];
            foreach ($columns as $name => $data) {
                $byId = array_combine($data['ids'], $data['values']) ?: [];
                $row[$name] = (int) ($byId[$id] ?? 0);
            }
            $rows[] = $row;
        }

        return SituationPanel::table('trends_organizations', $this->title('trends_organizations'), $this->hint('trends_organizations'), 'situationRoom.sources.overview', [
            ['key' => 'organization', 'label' => SituationLabel::key('situationRoom.columns.organization'), 'type' => 'label'],
            ...array_map(fn (string $key): array => ['key' => $key, 'label' => SituationLabel::key("situationRoom.kpi.{$key}"), 'type' => 'number'], array_keys($columns)),
        ], $rows, span: 3);
    }
}
