<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Business;

use App\Enums\EstablishmentStatus;
use App\Enums\ServiceFeedbackType;
use App\Services\Intelligence\Forecasting\Forecaster;
use App\Services\Intelligence\IntelligenceAnalysis;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\SituationRoom\OrganizationRollup;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationMemo;
use App\Services\SituationRoom\SituationPanel;
use App\Services\SituationRoom\SituationSettings;
use App\Support\Database\SqlDateBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Forecasting: the last twelve complete months of complaint volume and of
 * vacancy count in the scope, extended three months ahead by the linear
 * baseline (D-10). Shown only when forecasting is enabled in System
 * Settings › Intelligence; otherwise one NOT_CONFIGURED notice. Every
 * forecast states its method, data window, confidence and limitations next
 * to the numbers, and under six points says FORECAST_DATA_INSUFFICIENT.
 *
 * Vacancies are not snapshotted, so the monthly history is RECONSTRUCTED as
 * of each month's end from establishment and assignment effective dates on
 * positions that are active today (stated as a limitation). Snapshot
 * persistence is phase 4.
 */
final class ForecastingAnalysis extends IntelligenceAnalysis
{
    use BusinessQueries;

    public const HISTORY_MONTHS = 12;

    public const HORIZON_MONTHS = 3;

    public function __construct(
        OrganizationRollup $rollup,
        SituationSettings $settings,
        private readonly IntelligenceSettings $intelligence,
        private readonly PublicVoiceAnalysis $publicVoice,
    ) {
        parent::__construct($rollup, $settings);
    }

    public function panels(SituationContext $context): array
    {
        if (! $this->intelligence->forecastingEnabled()) {
            return ['forecast_disabled' => fn (): SituationPanel => $this->notice('forecast_disabled', $this->source('forecasts'), 'NOT_CONFIGURED', 3)];
        }

        return [
            'forecast_complaints_history' => fn (): SituationPanel => $this->history('complaints', $this->complaintsSeries($context)),
            'forecast_complaints' => fn (): SituationPanel => $this->forecastChart('complaints', $this->complaintsSeries($context)),
            'forecast_complaints_method' => fn (): SituationPanel => $this->method('complaints', $this->complaintsSeries($context)),
            'forecast_vacancies_history' => fn (): SituationPanel => $this->history('vacancies', $this->vacanciesSeries($context)),
            'forecast_vacancies' => fn (): SituationPanel => $this->forecastChart('vacancies', $this->vacanciesSeries($context)),
            'forecast_vacancies_method' => fn (): SituationPanel => $this->method('vacancies', $this->vacanciesSeries($context)),
        ];
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (! $this->intelligence->forecastingEnabled()) {
            return $this->notice('forecast_headline', $this->source('forecasts'), 'NOT_CONFIGURED', 3);
        }
        $complaints = Forecaster::linear($this->complaintsSeries($context)['values'], self::HORIZON_MONTHS);
        $vacancies = Forecaster::linear($this->vacanciesSeries($context)['values'], self::HORIZON_MONTHS);

        return SituationPanel::kpis('forecast_headline', $this->title('forecast_headline'), $this->source('forecasts'), [
            $this->kpi('forecast_complaints_next_month', $complaints['forecast'][0]['value'] ?? null),
            $this->kpi('forecast_vacancies_next_month', $vacancies['forecast'][0]['value'] ?? null),
        ], $this->hint('forecast_headline'));
    }

    public function insights(SituationContext $context, array $panels): array
    {
        if (! $this->intelligence->forecastingEnabled()) {
            return [];
        }
        $insights = [];
        foreach (['complaints' => $this->complaintsSeries($context), 'vacancies' => $this->vacanciesSeries($context)] as $name => $series) {
            $fit = Forecaster::linear($series['values'], self::HORIZON_MONTHS, end($series['categories']) ?: null);
            if (($fit['insufficient'] ?? false) || $fit['slope'] <= 0 || $fit['r2'] < 0.4) {
                continue;
            }
            $last = (float) end($series['values']);
            $ahead = $fit['forecast'][self::HORIZON_MONTHS - 1];
            if ($last <= 0 || $ahead['value'] < $last * 1.2) {
                continue;
            }
            $insights[] = $this->insight("forecast_{$name}_rising", 'low', [
                'current' => $last, 'baseline' => $ahead['value'], 'percent' => round(100 * ($ahead['value'] - $last) / $last, 1), 'period' => $ahead['period'],
            ], [
                $this->evidence('last_actual', $last),
                $this->evidence('forecast_value', $ahead['value']),
                $this->evidence('forecast_band', $ahead['lower'].' – '.$ahead['upper']),
                $this->evidence('method', $fit['method']),
                $this->evidence('r2', $fit['r2']),
                $this->evidence('samples', $fit['points']),
            ], $this->change($ahead['value'], $last), confidence: $fit['confidence'], source: $this->source('forecasts'), metric: "forecast_{$name}");
        }

        return $insights;
    }

    /** @return array{categories: list<string>, values: list<int>} */
    private function complaintsSeries(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:forecast:complaints'), function () use ($context): array {
            $window = $this->completeMonths(self::HISTORY_MONTHS);
            $series = $this->series($this->publicVoice->submissions($context, ServiceFeedbackType::Complaint), 'f.submitted_at', $window['from'], $window['to'], SqlDateBucket::MONTH);

            return $this->fromFirstRecord($series, $this->publicVoice->submissions($context, ServiceFeedbackType::Complaint)->min('f.submitted_at'));
        });
    }

    /** @return array{categories: list<string>, values: list<int>} */
    private function vacanciesSeries(SituationContext $context): array
    {
        return app(SituationMemo::class)->remember(SituationMemo::key($context, 'intelligence:forecast:vacancies'), function () use ($context): array {
            $window = $this->completeMonths(self::HISTORY_MONTHS);
            $values = [];
            foreach ($window['months'] as $month) {
                $values[] = $this->vacanciesAsOf($context, CarbonImmutable::createFromFormat('!Y-m', $month, $window['from']->getTimezone())->endOfMonth());
            }
            $assignments = $context->scope->apply(DB::table('employee_assignments as ea'), 'ea.organization_id')->min('ea.effective_from');
            $establishments = $context->scope->apply(DB::table('position_establishments as pe')->whereNull('pe.deleted_at'), 'pe.organization_id')->min('pe.effective_from');
            $first = array_filter([$assignments, $establishments], fn ($d): bool => $d !== null);

            return $this->fromFirstRecord(['categories' => $window['months'], 'values' => $values], $first === [] ? null : min($first));
        });
    }

    /**
     * Months before the first record are not history, they are absence of
     * data: a series that starts there would be padded with zeros and would
     * pass the six-point rule on nothing. The series starts at the month of
     * the first record; no record at all is an empty series.
     *
     * @param  array{categories: list<string>, values: list<int>}  $series
     * @return array{categories: list<string>, values: list<int>}
     */
    private function fromFirstRecord(array $series, mixed $firstRecordedAt): array
    {
        if ($firstRecordedAt === null) {
            return ['categories' => [], 'values' => []];
        }
        $firstMonth = CarbonImmutable::parse((string) $firstRecordedAt)->format('Y-m');
        $offset = 0;
        foreach ($series['categories'] as $i => $month) {
            if ($month >= $firstMonth) {
                $offset = $i;
                break;
            }
            $offset = $i + 1;
        }

        return ['categories' => array_slice($series['categories'], $offset), 'values' => array_slice($series['values'], $offset)];
    }

    /** Rule D-2 as of a past date: slots approved and effective then, occupants whose assignment window covered then. */
    private function vacanciesAsOf(SituationContext $context, CarbonImmutable $asOf): int
    {
        $day = $asOf->toDateString();
        $establishment = DB::table('position_establishments')
            ->selectRaw('position_id, SUM(approved_slots) AS slots')
            ->where('status', EstablishmentStatus::Approved->value)->whereNull('deleted_at')
            ->whereRaw('DATE(effective_from) <= ?', [$day])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereRaw('DATE(effective_to) >= ?', [$day]))
            ->groupBy('position_id');
        $occupants = DB::table('employee_assignments')
            ->selectRaw('position_id, COUNT(*) AS n')
            ->whereNotNull('position_id')
            ->whereRaw('DATE(effective_from) <= ?', [$day])
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereRaw('DATE(effective_to) >= ?', [$day]))
            ->groupBy('position_id');
        $capacity = 'CASE WHEN COALESCE(est.slots, 0) > 0 THEN est.slots ELSE 1 END';
        $held = 'COALESCE(occ.n, 0)';
        $query = DB::table('positions as p')
            ->leftJoinSub($establishment, 'est', 'est.position_id', '=', 'p.id')
            ->leftJoinSub($occupants, 'occ', 'occ.position_id', '=', 'p.id')
            ->whereNull('p.deleted_at')->where('p.is_active', true)
            ->where(fn ($q) => $q->whereNull('p.effective_from')->orWhereRaw('DATE(p.effective_from) <= ?', [$day]))
            ->selectRaw("COALESCE(SUM(CASE WHEN {$held} < {$capacity} THEN {$capacity} - {$held} ELSE 0 END), 0) AS vacant");
        $context->scope->apply($query, 'p.organization_id');

        return (int) $query->value('vacant');
    }

    /** @param  array{categories: list<string>, values: list<int>}  $series */
    private function history(string $name, array $series): SituationPanel
    {
        return $this->chart("forecast_{$name}_history", 'line', $this->source($name === 'complaints' ? 'public_submissions' : 'workforce'), $series['categories'], [
            ['key' => 'actual', 'label' => SituationLabel::key(self::PREFIX.'series.actual'), 'values' => $series['values']],
        ], span: 2);
    }

    /** @param  array{categories: list<string>, values: list<int>}  $series */
    private function forecastChart(string $name, array $series): SituationPanel
    {
        $fit = Forecaster::linear($series['values'], self::HORIZON_MONTHS, end($series['categories']) ?: null);
        if ($fit['insufficient'] ?? false) {
            return $this->notice("forecast_{$name}", $this->source('forecasts'), 'FORECAST_DATA_INSUFFICIENT');
        }

        return $this->chart("forecast_{$name}", 'line', $this->source('forecasts'), array_column($fit['forecast'], 'period'), [
            ['key' => 'lower', 'label' => SituationLabel::key(self::PREFIX.'series.lower'), 'values' => array_column($fit['forecast'], 'lower')],
            ['key' => 'forecast', 'label' => SituationLabel::key(self::PREFIX.'series.forecast'), 'values' => array_column($fit['forecast'], 'value')],
            ['key' => 'upper', 'label' => SituationLabel::key(self::PREFIX.'series.upper'), 'values' => array_column($fit['forecast'], 'upper')],
        ]);
    }

    /** Method, window, fit, confidence and every limitation, as a table beside the chart. @param  array{categories: list<string>, values: list<int>}  $series */
    private function method(string $name, array $series): SituationPanel
    {
        $fit = Forecaster::linear($series['values'], self::HORIZON_MONTHS, end($series['categories']) ?: null);
        $window = $series['categories'] === [] ? '—' : reset($series['categories']).' – '.end($series['categories']);
        $rows = [
            ['property' => SituationLabel::key(self::PREFIX.'forecast.window'), 'value' => $window],
            ['property' => SituationLabel::key(self::PREFIX.'forecast.points'), 'value' => (string) $fit['points']],
        ];
        // Every value is a dictionary key or a number, so the table reads in either language.
        if ($fit['insufficient'] ?? false) {
            $rows[] = ['property' => SituationLabel::key(self::PREFIX.'forecast.status'), 'value' => SituationLabel::key(self::PREFIX.'reasons.FORECAST_DATA_INSUFFICIENT.title')];
        } else {
            $rows[] = ['property' => SituationLabel::key(self::PREFIX.'forecast.method'), 'value' => SituationLabel::key(self::PREFIX.'forecast.methods.'.$fit['method'])];
            $rows[] = ['property' => SituationLabel::key(self::PREFIX.'forecast.slope'), 'value' => (string) $fit['slope']];
            $rows[] = ['property' => SituationLabel::key(self::PREFIX.'forecast.r2'), 'value' => (string) $fit['r2']];
            $rows[] = ['property' => SituationLabel::key(self::PREFIX.'forecast.confidence'), 'value' => SituationLabel::key(self::PREFIX.'confidence.'.$fit['confidence'])];
            $limitations = $fit['limitations'];
            if ($name === 'vacancies') {
                $limitations[] = 'reconstructed_vacancies';
            }
            foreach ($limitations as $limitation) {
                $rows[] = ['property' => SituationLabel::key(self::PREFIX.'forecast.limitation'), 'value' => SituationLabel::key(self::PREFIX.'forecast.limitations.'.$limitation)];
            }
        }

        return SituationPanel::table("forecast_{$name}_method", $this->title("forecast_{$name}_method"), $this->hint("forecast_{$name}_method"), $this->source('forecasts'), [
            $this->column('property', 'label'),
            $this->column('value', 'label'),
        ], $rows, span: 1);
    }
}
