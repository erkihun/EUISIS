<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\AssistantLabels;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligenceService;
use Illuminate\Http\Request;
use Throwable;

/**
 * `business_metric_query` (A-4): an approved Intelligence area computed
 * inside the person's own SituationScope, reduced to the KPIs the question
 * named (or the first few), the insights at or above "low", and a deep link.
 * The period is a preset; the scope is never taken from the message. Use is
 * audited as a sensitive tool.
 */
final class BusinessMetricTool
{
    public const KEY = 'business_metric_query';

    private const MAX_KPIS = 6;

    private const MAX_INSIGHTS = 4;

    private const RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(private readonly IntelligenceService $intelligence, private readonly WriteAuditLogAction $audit) {}

    /** @param  list<string>  $metrics */
    public function run(AssistantContext $context, IntelligenceArea $area, array $metrics, string $period): ToolResult
    {
        $locale = $context->locale;
        if (! $context->can('analytics')) {
            return ToolResult::denied(self::KEY, __('assistant.answers.denied_analytics', [], $locale));
        }
        if (! $area->visibleTo($context->user) || $area->domain() !== 'business') {
            return ToolResult::denied(self::KEY, __('assistant.answers.denied_analytics', [], $locale));
        }

        try {
            $situation = $this->intelligence->context($context->user, Request::create('/assistant', 'GET', ['period' => $period]));
            $payload = $this->intelligence->area($area, $situation)['payload'];
        } catch (Throwable $e) {
            report($e);

            return ToolResult::empty(self::KEY, __('assistant.answers.analytics_empty', [], $locale));
        }

        $this->audit->execute(AuditEventType::AssistantToolUsed, $context->user, null, newValues: ['tool' => self::KEY, 'area' => $area->value, 'period' => $period, 'metrics' => $metrics]);

        $kpis = [];
        foreach ($payload['panels'] as $panel) {
            if (($panel['type'] ?? '') !== 'kpis') {
                continue;
            }
            foreach ($panel['data']['items'] ?? [] as $item) {
                if ($metrics === [] || in_array($item['key'], $metrics, true)) {
                    $kpis[$item['key']] = ['label' => AssistantLabels::text($item['label'] ?? $item['key'], [], $locale), 'value' => $item['value'], 'change' => $item['change'] ?? null];
                }
            }
        }
        if ($metrics !== []) {
            $kpis = array_merge(array_flip(array_intersect($metrics, array_keys($kpis))), $kpis);
            $kpis = array_filter($kpis, 'is_array');
        }
        $kpis = array_slice($kpis, 0, self::MAX_KPIS, true);

        $insights = array_values(array_filter($payload['insights'], fn (array $i): bool => (self::RANK[$i['severity']] ?? 0) >= self::RANK['low']));
        usort($insights, fn (array $a, array $b): int => (self::RANK[$b['severity']] ?? 0) <=> (self::RANK[$a['severity']] ?? 0));
        $insights = array_slice($insights, 0, self::MAX_INSIGHTS);

        $areaLabel = (string) __('assistant.areas.'.$area->value, [], $locale);
        $periodLabel = __('situation_room.periods.'.$period, [], $locale);
        $periodLabel = is_string($periodLabel) && ! str_contains($periodLabel, '.') ? $periodLabel : $period;

        if ($kpis === [] && $insights === []) {
            return ToolResult::empty(self::KEY, __('assistant.answers.analytics_empty', [], $locale));
        }

        $blocks = [['type' => 'text', 'text' => __('assistant.answers.analytics_intro', ['area' => $areaLabel, 'period' => $periodLabel], $locale)]];
        $lines = [];
        if ($kpis !== []) {
            $rows = [];
            foreach ($kpis as $kpi) {
                $value = $kpi['value'] === null ? '—' : (string) $kpi['value'];
                $rows[] = ['label' => $kpi['label'], 'value' => $value];
                $lines[] = $kpi['label'].': '.$value;
            }
            $blocks[] = ['type' => 'evidence', 'title' => $areaLabel, 'rows' => $rows, 'source' => $areaLabel, 'updated_at' => now()->toIso8601String()];
            $blocks[] = ['type' => 'labels', 'kind' => 'calculated', 'text' => __('assistant.answers.analytics_count_note', [], $locale)];
        }
        $insightData = [];
        foreach ($insights as $insight) {
            $title = AssistantLabels::text($insight['title'], (array) $insight['title_params'], $locale);
            $summary = AssistantLabels::text($insight['summary'], (array) $insight['summary_params'], $locale);
            $blocks[] = ['type' => 'labels', 'kind' => 'observed', 'text' => '['.$insight['severity'].'] '.$title.($summary !== '' ? ' — '.$summary : '')];
            $lines[] = $title.($summary !== '' ? ' — '.$summary : '');
            $insightData[] = ['severity' => $insight['severity'], 'title' => $title, 'summary' => $summary, 'confidence' => $insight['confidence'] ?? null];
        }
        $href = $area->href();
        $blocks[] = ['type' => 'actions', 'items' => [['key' => 'open_area', 'label' => __('assistant.answers.analytics_open', ['area' => $areaLabel], $locale), 'href' => parse_url($href, PHP_URL_PATH) ?: '/intelligence']]];

        return new ToolResult(self::KEY, 'ok', $blocks, ['area' => $areaLabel, 'period' => $periodLabel, 'kpis' => $kpis, 'insights' => $insightData], $lines, 'aggregates');
    }
}
