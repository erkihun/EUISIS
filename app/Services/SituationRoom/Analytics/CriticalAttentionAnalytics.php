<?php

declare(strict_types=1);

namespace App\Services\SituationRoom\Analytics;

use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\FieldWork\Contracts\Data\FieldWorkStatus;

/**
 * Critical Attention: items that have waited longer than the city decided
 * they should. Every rule reads its threshold from the `situation_room`
 * settings; a rule nobody configured shows CONFIGURATION_REQUIRED and counts
 * nothing — the room never invents a cut-off. A rule is shown only to
 * viewers who may see its domain.
 *
 * What is flagged is a delay in handling, never a person: each rule counts
 * items per organization row, in name order.
 */
final class CriticalAttentionAnalytics extends SituationAnalytics
{
    /** @return array<string, array{permission: string, source: string}> */
    public static function rules(): array
    {
        return [
            'complaint_open_days' => ['permission' => SituationPermissions::PUBLIC_VOICE, 'source' => PublicVoiceAnalytics::SOURCE],
            'tip_off_unassigned_days' => ['permission' => SituationPermissions::PUBLIC_VOICE, 'source' => PublicVoiceAnalytics::SOURCE],
            'daily_review_backlog_days' => ['permission' => SituationPermissions::OPERATIONS, 'source' => DailyOperationsAnalytics::SOURCE],
            'field_work_overdue_hours' => ['permission' => SituationPermissions::OPERATIONS, 'source' => FieldWorkAnalytics::SOURCE],
            'vacancy_rate_percent' => ['permission' => SituationPermissions::WORKFORCE, 'source' => 'situationRoom.sources.establishment'],
        ];
    }

    public function panels(SituationContext $context): array
    {
        $panels = ['attention_kpis' => fn (): SituationPanel => $this->kpis($context)];

        foreach (self::rules() as $rule => $meta) {
            if ($context->can($meta['permission'])) {
                $panels["attention_{$rule}"] = fn (): SituationPanel => $this->rulePanel($context, $rule, $meta['source']);
            }
        }

        return $panels;
    }

    /**
     * Items needing attention under one rule, per organization in scope.
     * Null when the rule has no configured threshold.
     *
     * @return array<string, int>|null
     */
    public function counts(SituationContext $context, string $rule): ?array
    {
        $threshold = $this->settings->threshold($rule);

        if ($threshold === null) {
            return null;
        }

        $now = CarbonImmutable::now();

        $grouped = function ($query, string $column): array {
            return $this->countBy($query, $column);
        };

        return match ($rule) {
            'complaint_open_days' => $grouped(
                $context->scope->apply(DB::table('employee_service_feedback as f'), 'f.organization_id')
                    ->where('f.type', 'complaint')->whereIn('f.status', PublicVoiceAnalytics::OPEN)
                    ->where('f.submitted_at', '<', $now->subDays($threshold)->toDateTimeString()),
                'f.organization_id',
            ),
            'tip_off_unassigned_days' => $grouped(
                $context->scope->apply(DB::table('employee_service_feedback as f'), 'f.organization_id')
                    ->where('f.type', 'suggestion')->whereIn('f.status', ['pending', 'unrouted'])
                    ->where('f.submitted_at', '<', $now->subDays($threshold)->toDateTimeString()),
                'f.organization_id',
            ),
            'daily_review_backlog_days' => $grouped(
                DailyOperationsAnalytics::base($context)->whereIn('l.status', DailyOperationsAnalytics::AWAITING_REVIEW)
                    ->where('l.submitted_at', '<', $now->subDays($threshold)->toDateTimeString()),
                'l.organization_id',
            ),
            'field_work_overdue_hours' => $grouped(
                FieldWorkAnalytics::base($context)->where('r.status', FieldWorkStatus::InField->value)
                    ->where('r.expected_return_at', '<', $now->subHours($threshold)->toDateTimeString()),
                'r.organization_id',
            ),
            'vacancy_rate_percent' => $this->vacancyBreaches($context, $threshold),
            default => null,
        };
    }

    private function kpis(SituationContext $context): SituationPanel
    {
        $items = [];

        foreach (self::rules() as $rule => $meta) {
            if (! $context->can($meta['permission'])) {
                continue;
            }
            $counts = $this->counts($context, $rule);
            $items[] = $this->kpi("attention_{$rule}", $counts === null ? null : array_sum($counts), [
                'state' => $counts === null ? 'not_configured' : (array_sum($counts) > 0 ? 'attention' : 'clear'),
                'threshold' => $this->settings->threshold($rule),
            ]);
        }

        return SituationPanel::kpis('attention_kpis', $this->title('attention_kpis'), 'situationRoom.sources.settings', $items);
    }

    private function rulePanel(SituationContext $context, string $rule, string $source): SituationPanel
    {
        $key = "attention_{$rule}";
        $counts = $this->counts($context, $rule);

        if ($counts === null) {
            return $this->notice($key, $source, 'CONFIGURATION_REQUIRED');
        }
        if (array_sum($counts) === 0) {
            return $this->notice($key, $source, 'ALL_CLEAR');
        }

        $rolled = $this->rolledUp($context, $counts);

        return $this->chart($key, 'hbar', $source, $rolled['categories'], [
            ['key' => 'items', 'label' => SituationLabel::key("situationRoom.series.attention_{$rule}"), 'values' => $rolled['values']],
        ]);
    }

    /**
     * Organizations (not roll-up rows) whose vacancy rate meets the threshold:
     * a count of 1 per breaching organization, so a parent row shows how many
     * of its organizations breach, not a blended rate that hides them.
     *
     * @return array<string, int>
     */
    private function vacancyBreaches(SituationContext $context, int $threshold): array
    {
        $breaches = [];

        foreach (WorkforceQueries::capacityByOrganization($context) as $organizationId => $row) {
            if ($row['capacity'] > 0 && 100 * $row['vacant'] / $row['capacity'] >= $threshold) {
                $breaches[$organizationId] = 1;
            }
        }

        return $breaches;
    }
}
