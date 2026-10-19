<?php

declare(strict_types=1);

use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use Carbon\CarbonImmutable;
use Tests\Support\IntelligenceBusinessFixture as F;
use Tests\Support\SituationRoomScenario;

/*
 * Forecasting: off until enabled (NOT_CONFIGURED), FORECAST_DATA_INSUFFICIENT
 * under six monthly points, and when there is history a linear baseline
 * that states its method, window, confidence and limitations.
 */

beforeEach(function (): void {
    F::createPermissions();
    $this->s = SituationRoomScenario::build();
    $this->grants = [...F::viewerGrants(), IntelligencePermissions::FORECASTING];
});

it('is a single NOT_CONFIGURED notice until forecasting is enabled', function (): void {
    $payload = F::area(IntelligenceArea::Forecasting, $this->s->viewer($this->grants, 'subcity_a'));

    expect($payload['panels'])->toHaveCount(1)
        ->and($payload['panels'][0]['key'])->toBe('forecast_disabled')
        ->and($payload['panels'][0]['reason'])->toBe('NOT_CONFIGURED')
        ->and($payload['insights'])->toBe([]);
});

it('says FORECAST_DATA_INSUFFICIENT with fewer than six monthly points', function (): void {
    F::setting('intelligence', 'forecasting_enabled', '1', 'boolean');
    // Complaints exist only this month: zero complete months of history.
    $payload = F::area(IntelligenceArea::Forecasting, $this->s->viewer($this->grants, 'subcity_a'));

    expect($payload['has_errors'])->toBeFalse()
        ->and(F::panel($payload['panels'], 'forecast_complaints')['reason'])->toBe('FORECAST_DATA_INSUFFICIENT')
        ->and(F::panel($payload['panels'], 'forecast_complaints_method')['type'])->toBe('table');
});

it('forecasts three months on twelve months of history and states method, window, confidence and limitations', function (): void {
    F::setting('intelligence', 'forecasting_enabled', '1', 'boolean');
    $start = CarbonImmutable::now()->startOfMonth();
    for ($m = 12; $m >= 1; $m--) {
        for ($i = 0; $i < 13 - $m; $i++) {
            $this->s->submission($this->s->org['woreda_a1'], 'complaint', ['submitted_at' => $start->subMonthsNoOverflow($m)->addDays(3 + $i)]);
        }
    }

    $payload = F::area(IntelligenceArea::Forecasting, $this->s->viewer($this->grants, 'subcity_a'));
    expect($payload['has_errors'])->toBeFalse();
    $history = F::panel($payload['panels'], 'forecast_complaints_history');
    expect($history['type'])->toBe('line')->and($history['data']['series'][0]['values'])->toBe(range(1, 12));

    $forecast = F::panel($payload['panels'], 'forecast_complaints');
    expect($forecast['type'])->toBe('line')->and($forecast['data']['categories'])->toHaveCount(3)
        ->and($forecast['data']['categories'][0])->toBe($start->format('Y-m'))
        ->and(collect($forecast['data']['series'])->firstWhere('key', 'forecast')['values'][0])->toBe(13.0);

    $method = F::panel($payload['panels'], 'forecast_complaints_method');
    $rows = collect($method['data']['rows']);
    expect($rows->firstWhere('property.key', 'intelligence.forecast.method')['value']['key'])->toBe('intelligence.forecast.methods.least_squares_linear')
        ->and($rows->firstWhere('property.key', 'intelligence.forecast.confidence')['value']['key'])->toBe('intelligence.confidence.high')
        ->and($rows->firstWhere('property.key', 'intelligence.forecast.points')['value'])->toBe('12')
        ->and($rows->where('property.key', 'intelligence.forecast.limitation')->count())->toBeGreaterThanOrEqual(3);

    $rising = F::insight($payload['insights'], 'forecast_complaints_rising');
    expect($rising)->not->toBeNull()->and($rising['confidence'])->toBe('high');

    // Vacancies: twelve reconstructed points, no vacancy in the fixture, so a flat zero line.
    $vacancies = F::panel($payload['panels'], 'forecast_vacancies_history');
    expect($vacancies['reason'])->toBe('NO_DATA');
    $sibling = F::area(IntelligenceArea::Forecasting, $this->s->viewer($this->grants, 'subcity_b'));
    expect(F::panel($sibling['panels'], 'forecast_complaints')['reason'])->toBe('FORECAST_DATA_INSUFFICIENT');
});
