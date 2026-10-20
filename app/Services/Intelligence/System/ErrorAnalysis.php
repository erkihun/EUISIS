<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;

/**
 * Error & Failure Analysis (brief §34): repeated exception signatures by
 * route group and component. Shows class names, normalised messages, counts
 * and the first application frame — never arguments, input or a person.
 */
final class ErrorAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'error_headline' => fn (): SituationPanel => $this->headline($context),
            'top_fingerprints' => fn (): SituationPanel => $this->topFingerprints($context),
            'fingerprints_by_group' => fn (): SituationPanel => $this->byGroup($context),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        return $this->incidentInsights(Metrics::components(), 6);
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('error_headline', $this->source('error_fingerprints'), 'TELEMETRY_OFF', 3);
        }
        [$from] = $this->window($context);
        $active = IntelligenceErrorFingerprint::query()->where('last_seen_at', '>=', $from->toDateTimeString());

        return SituationPanel::kpis('error_headline', $this->title('error_headline'), $this->source('error_fingerprints'), [
            $this->kpi('fingerprints_active', (clone $active)->count()),
            $this->kpi('occurrences_recent', (int) (clone $active)->sum('occurrences')),
            $this->kpi('new_signatures', IntelligenceErrorFingerprint::query()->where('first_seen_at', '>=', $from->toDateTimeString())->count()),
            $this->kpi('incidents_from_errors', IntelligenceIncident::query()->where('rule', 'error_repeat')->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->count(), ['href' => route('intelligence.incidents.index')]),
        ], $this->hint('error_headline'));
    }

    private function topFingerprints(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('top_fingerprints', $this->source('error_fingerprints'), 'TELEMETRY_OFF', 3);
        }
        [$from] = $this->window($context);
        $rows = IntelligenceErrorFingerprint::query()->where('last_seen_at', '>=', $from->toDateTimeString())
            ->orderByDesc('occurrences')->limit($context->topN * 2)->get()
            ->map(fn (IntelligenceErrorFingerprint $f): array => [
                'key' => $f->fingerprint,
                'cells' => [
                    'exception' => class_basename($f->exception_class),
                    'message' => mb_substr($f->message_normalized, 0, 140),
                    'route_group' => SituationLabel::key(self::PREFIX."values.route_group.{$f->route_group}"),
                    'component' => $f->component ?? '',
                    'occurrences' => (int) $f->occurrences,
                    'first_seen' => $f->first_seen_at->toIso8601String(),
                    'last_seen' => $f->last_seen_at->toIso8601String(),
                ],
                'href' => $f->incident_id !== null ? route('intelligence.incidents.show', $f->incident_id) : null,
            ])->all();

        return SituationPanel::table('top_fingerprints', $this->title('top_fingerprints'), $this->hint('top_fingerprints'), $this->source('error_fingerprints'), [
            ['key' => 'exception', 'label' => SituationLabel::key(self::PREFIX.'columns.exception'), 'type' => 'text'],
            ['key' => 'message', 'label' => SituationLabel::key(self::PREFIX.'columns.message'), 'type' => 'text'],
            ['key' => 'route_group', 'label' => SituationLabel::key(self::PREFIX.'columns.route_group'), 'type' => 'label'],
            ['key' => 'component', 'label' => SituationLabel::key(self::PREFIX.'columns.component'), 'type' => 'text'],
            ['key' => 'occurrences', 'label' => SituationLabel::key(self::PREFIX.'columns.occurrences'), 'type' => 'number'],
            ['key' => 'first_seen', 'label' => SituationLabel::key(self::PREFIX.'columns.first_seen'), 'type' => 'date'],
            ['key' => 'last_seen', 'label' => SituationLabel::key(self::PREFIX.'columns.last_seen'), 'type' => 'date'],
        ], $rows, span: 3);
    }

    private function byGroup(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('fingerprints_by_group', $this->source('error_fingerprints'), 'TELEMETRY_OFF');
        }
        [$from] = $this->window($context);
        $counts = IntelligenceErrorFingerprint::query()->where('last_seen_at', '>=', $from->toDateTimeString())
            ->selectRaw('route_group, sum(occurrences) as total')->groupBy('route_group')->pluck('total', 'route_group')
            ->mapWithKeys(fn ($total, $group): array => [(string) $group => (int) $total])->all();
        $top = $this->topWithOther($counts);

        return $this->chart('fingerprints_by_group', 'hbar', $this->source('error_fingerprints'),
            array_map(fn (string $g): array => SituationLabel::key(self::PREFIX."values.route_group.{$g}"), array_keys($top)),
            [['key' => 'occurrences', 'label' => SituationLabel::key(self::PREFIX.'series.occurrences'), 'values' => array_values($top)]]);
    }
}
