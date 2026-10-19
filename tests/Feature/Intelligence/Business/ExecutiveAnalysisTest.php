<?php

declare(strict_types=1);

use App\Models\Position;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use Tests\Support\IntelligenceBusinessFixture as F;
use Tests\Support\SituationRoomScenario;

/*
 * Executive Intelligence: the headline strip, the attention table over the
 * roll-up rows, insights gathered from the other areas under the executive
 * grant alone, and no person in the payload.
 */

beforeEach(function (): void {
    F::createPermissions();
    $this->s = SituationRoomScenario::build();
    $this->grants = [...F::viewerGrants(), IntelligencePermissions::EXECUTIVE];
});

it('shows the headline strip and gathers the other areas\' insights under the executive grant alone', function (): void {
    F::establishment(Position::query()->where('organization_id', $this->s->org['woreda_a1']->id)->firstOrFail(), 5);
    $payload = F::area(IntelligenceArea::Executive, $this->s->viewer($this->grants, 'subcity_a'));

    expect($payload['has_errors'])->toBeFalse();
    $kpis = F::panel($payload['panels'], 'executive_kpis');
    expect(F::kpi($kpis, 'vacancy_rate'))->toBe(28.6)
        ->and(F::kpi($kpis, 'complaints_open'))->toBe(6)
        ->and(F::kpi($kpis, 'agreement_coverage'))->toBeNull()
        ->and(F::kpi($kpis, 'checks_failing'))->toBeGreaterThan(0);

    $keys = array_map(fn (array $i): string => $i['key'], $payload['insights']);
    expect($keys)->toContain('assignment_anomalies')->toContain('data_quality_fail');
    $weights = array_map(fn (array $i): int => array_search($i['severity'], ['info', 'low', 'medium', 'high', 'critical'], true), $payload['insights']);
    $sorted = $weights;
    rsort($sorted);
    expect($weights)->toBe($sorted);
});

it('lists only the roll-up rows where a measure is abnormal', function (): void {
    F::setting('situation_room', 'vacancy_rate_percent', '10');
    F::establishment(Position::query()->where('organization_id', $this->s->org['woreda_a1']->id)->firstOrFail(), 5);

    $table = F::panel(F::area(IntelligenceArea::Executive, $this->s->viewer($this->grants, 'bureau'))['panels'], 'executive_attention');
    expect($table['type'])->toBe('table')->and($table['data']['rows'])->toHaveCount(1)
        ->and($table['data']['rows'][0]['organization']['en'])->toBe('Subcity A')
        ->and($table['data']['rows'][0]['attention']['key'])->toBe('intelligence.values.attention.vacancy_threshold');

    $clear = F::panel(F::area(IntelligenceArea::Executive, $this->s->viewer($this->grants, 'subcity_b'))['panels'], 'executive_attention');
    expect($clear['reason'])->toBe('ALL_CLEAR');
});

it('never carries a person', function (): void {
    $json = json_encode(F::area(IntelligenceArea::Executive, $this->s->viewer($this->grants, 'bureau')), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('Person 0')->not->toContain('SR-E-')->not->toContain('Private Submitter');
});
