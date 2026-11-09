<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Forecasting;

use App\Models\IntelligenceForecast;
use App\Services\Intelligence\Business\ForecastingAnalysis;
use App\Services\SituationRoom\SituationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Forecast snapshots (brief §69–71): each run writes the predicted points of
 * each metric with method, model version, window, confidence, assumptions
 * and limitations, and never overwrites an earlier snapshot. When a target
 * month has passed, `evaluate()` writes the actual and the error beside the
 * prediction, so Governance can show MAE / MAPE per metric over time.
 */
final class ForecastSnapshotService
{
    public const MODEL_VERSION = 'linear-v1';

    public const METRICS = ['complaints_monthly', 'vacancies_monthly'];

    public const HORIZON = 3;

    public function __construct(private readonly ForecastingAnalysis $analysis) {}

    /** @return array{snapshot_id: string, written: int, insufficient: list<string>} */
    public function snapshot(SituationContext $context, string $scopeKey = 'all'): array
    {
        $snapshotId = (string) Str::uuid7();
        $now = CarbonImmutable::now();
        $written = 0;
        $insufficient = [];

        foreach (self::METRICS as $metric) {
            $series = $this->series($metric, $context);
            $last = $series['categories'] === [] ? null : (string) end($series['categories']);
            $result = Forecaster::linear($series['values'], self::HORIZON, $last);
            if (($result['insufficient'] ?? false) || ! isset($result['forecast'])) {
                $insufficient[] = $metric;

                continue;
            }
            foreach ($result['forecast'] as $point) {
                IntelligenceForecast::query()->create([
                    'snapshot_id' => $snapshotId,
                    'metric' => $metric,
                    'scope_key' => $scopeKey,
                    'period' => $point['period'],
                    'value' => $point['value'],
                    'lower' => $point['lower'],
                    'upper' => $point['upper'],
                    'method' => $result['method'] ?? Forecaster::METHOD,
                    'model_version' => self::MODEL_VERSION,
                    'confidence' => $result['confidence'] ?? null,
                    'points' => $result['points'],
                    'r2' => $result['r2'] ?? null,
                    'assumptions' => ['window_months' => $result['points'], 'data_through' => $last, 'series_start' => $series['categories'][0] ?? null],
                    'limitations' => $result['limitations'] ?? [],
                    'data_through' => (string) $last,
                    'generated_at' => $now,
                    'created_at' => $now,
                ]);
                $written++;
            }
        }

        return ['snapshot_id' => $snapshotId, 'written' => $written, 'insufficient' => $insufficient];
    }

    /** Writes actuals and errors for predicted months that have passed. @return array{evaluated: int} */
    public function evaluate(SituationContext $context, string $scopeKey = 'all'): array
    {
        $currentMonth = CarbonImmutable::now()->format('Y-m');
        $evaluated = 0;

        foreach (self::METRICS as $metric) {
            $pending = IntelligenceForecast::query()->where('metric', $metric)->where('scope_key', $scopeKey)->whereNull('actual')->where('period', '<', $currentMonth)->get();
            if ($pending->isEmpty()) {
                continue;
            }
            $series = $this->series($metric, $context);
            $actuals = array_combine($series['categories'], $series['values']) ?: [];
            foreach ($pending as $row) {
                if (! array_key_exists($row->period, $actuals)) {
                    continue;
                }
                $actual = (float) $actuals[$row->period];
                $row->forceFill([
                    'actual' => $actual,
                    'abs_error' => abs($actual - (float) $row->value),
                    'pct_error' => $actual != 0.0 ? round(100 * abs($actual - (float) $row->value) / $actual, 2) : null,
                    'evaluated_at' => now(),
                ])->save();
                $evaluated++;
            }
        }

        return ['evaluated' => $evaluated];
    }

    /**
     * Forecast quality so far: MAE and MAPE per metric over evaluated points.
     *
     * @return array<string, array{evaluated: int, mae: ?float, mape: ?float, last_generated_at: ?string}>
     */
    public function quality(string $scopeKey = 'all'): array
    {
        $out = [];
        foreach (self::METRICS as $metric) {
            $rows = IntelligenceForecast::query()->where('metric', $metric)->where('scope_key', $scopeKey)->whereNotNull('actual')->get(['abs_error', 'pct_error']);
            $last = IntelligenceForecast::query()->where('metric', $metric)->where('scope_key', $scopeKey)->max('generated_at');
            $pct = $rows->whereNotNull('pct_error');
            $out[$metric] = [
                'evaluated' => $rows->count(),
                'mae' => $rows->isEmpty() ? null : round((float) $rows->avg('abs_error'), 2),
                'mape' => $pct->isEmpty() ? null : round((float) $pct->avg('pct_error'), 1),
                'last_generated_at' => $last !== null ? CarbonImmutable::parse($last)->toIso8601String() : null,
            ];
        }

        return $out;
    }

    /** @return array{categories: list<string>, values: list<int>} */
    private function series(string $metric, SituationContext $context): array
    {
        return $metric === 'complaints_monthly' ? $this->analysis->complaintsSeries($context) : $this->analysis->vacanciesSeries($context);
    }
}
