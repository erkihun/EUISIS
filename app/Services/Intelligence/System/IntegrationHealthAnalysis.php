<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;
use Carbon\CarbonImmutable;

/**
 * Integration Health (brief §42): per provider calls, failures, failure rate
 * and last successful call from the usage records; external API clients from
 * the API request log; broadcast failures. E-mail delivery is not recorded
 * anywhere (NEEDS_DATA). Credentials never appear.
 */
final class IntegrationHealthAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'integration_headline' => fn (): SituationPanel => $this->headline($context),
            'integrations_table' => fn (): SituationPanel => $this->table($context),
            'api_requests_trend' => fn (): SituationPanel => $this->apiTrend($context),
            'email_delivery' => fn (): SituationPanel => $this->notice('email_delivery', $this->source('integrations'), 'NEEDS_DATA'),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        return $this->incidentInsights([Metrics::COMPONENT_INTEGRATIONS], 5);
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('integration_headline', $this->source('integrations'), 'TELEMETRY_OFF', 3);
        }
        [$from, $to] = $this->window($context);
        $fresh = CarbonImmutable::now()->subMinutes(ComponentHealth::FRESH_MINUTES);

        return SituationPanel::kpis('integration_headline', $this->title('integration_headline'), $this->source('integrations'), [
            $this->kpi('integration_calls', (int) array_sum($this->signals->sumByDimension(Metrics::INTEGRATION_CALLS, $from, $to))),
            $this->kpi('integration_failures', (int) array_sum($this->signals->sumByDimension(Metrics::INTEGRATION_FAILURES, $from, $to))),
            $this->kpi('api_requests', (int) $this->signals->sum(Metrics::INTEGRATION_API_REQUESTS, null, $from, $to)),
            $this->kpi('api_failures', (int) $this->signals->sum(Metrics::INTEGRATION_API_FAILURES, null, $from, $to)),
            $this->kpi('broadcast_failures', $this->signals->latest(Metrics::INTEGRATION_BROADCAST_FAILURES_TOTAL, null, $fresh)['value'] ?? null),
        ], $this->hint('integration_headline'));
    }

    private function table(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('integrations_table', $this->source('integrations'), 'TELEMETRY_OFF', 3);
        }
        [$from, $to] = $this->window($context);
        $rows = [];
        foreach ($this->signals->dimensions(Metrics::INTEGRATION_CALLS, $from) as $provider) {
            $calls = $this->signals->series(Metrics::INTEGRATION_CALLS, $provider, $from, $to, 10000);
            $failures = $this->signals->series(Metrics::INTEGRATION_FAILURES, $provider, $from, $to, 10000);
            $failedAt = [];
            foreach ($failures as $s) {
                $failedAt[$s['bucket_start']] = $s['value'];
            }
            $totalCalls = 0.0;
            $totalFailures = 0.0;
            $lastSuccess = null;
            foreach ($calls as $s) {
                $totalCalls += $s['value'];
                $f = $failedAt[$s['bucket_start']] ?? 0.0;
                $totalFailures += $f;
                if ($s['value'] > $f) {
                    $lastSuccess = $s['bucket_start'];
                }
            }
            $rows[] = ['key' => $provider, 'cells' => [
                'provider' => $provider,
                'calls' => (int) $totalCalls,
                'failures' => (int) $totalFailures,
                'failure_rate' => $totalCalls > 0 ? round(100 * $totalFailures / $totalCalls, 1) : null,
                'last_success' => $lastSuccess,
            ]];
        }

        return SituationPanel::table('integrations_table', $this->title('integrations_table'), $this->hint('integrations_table'), $this->source('integrations'), [
            ['key' => 'provider', 'label' => SituationLabel::key(self::PREFIX.'columns.provider'), 'type' => 'text'],
            ['key' => 'calls', 'label' => SituationLabel::key(self::PREFIX.'columns.calls'), 'type' => 'number'],
            ['key' => 'failures', 'label' => SituationLabel::key(self::PREFIX.'columns.failures'), 'type' => 'number'],
            ['key' => 'failure_rate', 'label' => SituationLabel::key(self::PREFIX.'columns.failure_rate'), 'type' => 'percent'],
            ['key' => 'last_success', 'label' => SituationLabel::key(self::PREFIX.'columns.last_success'), 'type' => 'date'],
        ], $rows, span: 2);
    }

    private function apiTrend(SituationContext $context): SituationPanel
    {
        if (! $this->telemetryOn()) {
            return $this->notice('api_requests_trend', $this->source('integrations'), 'TELEMETRY_OFF');
        }
        [$from, $to, $grain] = $this->window($context);
        $requests = $this->signals->aggregate(Metrics::INTEGRATION_API_REQUESTS, null, $from, $to, $grain, 'sum');
        $failures = $this->signals->aggregate(Metrics::INTEGRATION_API_FAILURES, null, $from, $to, $grain, 'sum');
        if ($requests === []) {
            return $this->notice('api_requests_trend', $this->source('integrations'), 'UNKNOWN');
        }

        return $this->lineFrom('api_requests_trend', $this->source('integrations'), ['requests' => $requests[''] ?? [], 'failures' => $failures[''] ?? []], null, 1, 'series_name');
    }
}
