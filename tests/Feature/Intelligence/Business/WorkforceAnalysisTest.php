<?php

declare(strict_types=1);

use App\Models\Position;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\IntelligenceBusinessFixture as F;
use Tests\Support\SituationRoomScenario;

/*
 * Workforce Intelligence (docs/intelligence-business.md): the five staffing
 * figures stay distinct and come out exactly for the fixture, scope confines
 * every figure to the viewer's subtree, and no person is ever in a payload.
 *
 * Fixture: each organization has one position (capacity 1 without an
 * establishment). Woreda A1 gets an establishment of 5 slots and Subcity A an
 * approved manpower plan (7.5 FTE, 8 approved), so for Subcity A's subtree:
 * employees 6, positions 3, approved positions 1, approved slots 5,
 * capacity 7, occupied 5, vacant 2, required FTE 7.5, approved headcount 8.
 */

beforeEach(function (): void {
    F::createPermissions();
    $this->s = SituationRoomScenario::build();
    F::establishment(Position::query()->where('organization_id', $this->s->org['woreda_a1']->id)->firstOrFail(), 5);
    F::approvedPlan($this->s->org['subcity_a'], 7.5, 8);
    $this->grants = [...F::viewerGrants(), IntelligencePermissions::WORKFORCE];
});

it('keeps the five staffing figures distinct and exact for a sub-city viewer', function (): void {
    $payload = F::area(IntelligenceArea::Workforce, $this->s->viewer($this->grants, 'subcity_a'));
    $figures = F::panel($payload['panels'], 'workforce_figures');

    expect($payload['has_errors'])->toBeFalse()
        ->and(F::kpi($figures, 'required_fte'))->toBe(7.5)
        ->and(F::kpi($figures, 'approved_headcount'))->toBe(8)
        ->and(F::kpi($figures, 'approved_positions'))->toBe(1)
        ->and(F::kpi($figures, 'approved_slots'))->toBe(5)
        ->and(F::kpi($figures, 'capacity'))->toBe(7)
        ->and(F::kpi($figures, 'occupied_positions'))->toBe(5)
        ->and(F::kpi($figures, 'vacant_positions'))->toBe(2)
        ->and(F::kpi($figures, 'employee_count'))->toBe($this->s->headcount('subcity_a', 'woreda_a1', 'woreda_a2'))
        ->and(F::kpi($figures, 'vacancy_rate'))->toBe(28.6);

    // Every one of the five carries its definition so the page can say what it is not.
    foreach ($figures['data']['items'] as $item) {
        if (in_array($item['key'], ['required_fte', 'approved_headcount', 'approved_positions', 'occupied_positions', 'employee_count'], true)) {
            expect($item['definition'])->toBe('intelligence.kpiHint.'.$item['key']);
        }
    }
});

it('never shows a sibling sub-city its neighbour\'s figures, and rolls everything up for the bureau', function (): void {
    $sibling = F::panel(F::area(IntelligenceArea::Workforce, $this->s->viewer($this->grants, 'subcity_b'))['panels'], 'workforce_figures');
    expect(F::kpi($sibling, 'employee_count'))->toBe($this->s->headcount('subcity_b', 'woreda_b1'))
        ->and(F::kpi($sibling, 'approved_positions'))->toBe(0)
        ->and(F::kpi($sibling, 'required_fte'))->toBeNull()
        ->and(F::kpi($sibling, 'approved_headcount'))->toBeNull();

    $bureau = F::area(IntelligenceArea::Workforce, $this->s->viewer($this->grants, 'bureau'));
    $figures = F::panel($bureau['panels'], 'workforce_figures');
    expect(F::kpi($figures, 'employee_count'))->toBe(15)
        ->and(F::kpi($figures, 'required_fte'))->toBe(7.5)
        ->and(F::kpi($figures, 'capacity'))->toBe(5 + 5);

    $table = F::panel($bureau['panels'], 'workforce_by_organization');
    expect($table['type'])->toBe('table')->and($table['data']['rows'])->toHaveCount(3);
    $labels = array_map(fn (array $r): string => (string) $r['organization']['en'], $table['data']['rows']);
    expect($labels[0])->toContain('Bureau')->and($labels)->toContain('Subcity A')->and($labels)->toContain('Subcity B');
});

it('reports leadership vacancies as NEEDS_DATA and counts anomalies with an explainable insight', function (): void {
    $payload = F::area(IntelligenceArea::Workforce, $this->s->viewer($this->grants, 'subcity_a'));

    expect(F::panel($payload['panels'], 'workforce_leadership_vacancies')['reason'])->toBe('NEEDS_DATA');
    // Woreda A2 holds two people on a one-slot position: one position above capacity.
    $anomalies = F::panel($payload['panels'], 'workforce_anomalies');
    expect(F::kpi($anomalies, 'positions_over_capacity'))->toBe(1)
        ->and(F::kpi($anomalies, 'multiple_current_assignments'))->toBe(0);

    $insight = F::insight($payload['insights'], 'assignment_anomalies');
    expect($insight)->not->toBeNull()
        ->and($insight['severity'])->toBe('low')
        ->and($insight['summary_params']->count)->toBe(1)
        ->and(collect($insight['evidence'])->firstWhere('label.key', 'intelligence.evidence.positions_over_capacity')['value'])->toBe(1)
        ->and($insight['source'])->toBe('intelligence.sources.workforce');
});

it('raises the vacancy-rate insight only against a configured limit', function (): void {
    $viewer = $this->s->viewer($this->grants, 'subcity_a');
    expect(F::insight(F::area(IntelligenceArea::Workforce, $viewer)['insights'], 'vacancy_rate_above_threshold'))->toBeNull();

    F::setting('situation_room', 'vacancy_rate_percent', '10');
    $insight = F::insight(F::area(IntelligenceArea::Workforce, $viewer)['insights'], 'vacancy_rate_above_threshold');
    expect($insight)->not->toBeNull()
        ->and($insight['severity'])->toBe('high')
        ->and($insight['change']['current'])->toBe(28.6)
        ->and($insight['change']['baseline'])->toBe(10);
});

it('serves the area over HTTP as a deferred prop without any personal data', function (): void {
    $json = '';
    $this->actingAs($this->s->viewer($this->grants, 'subcity_a'))
        ->get(route('intelligence.area', 'workforce'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$json): void {
            $page->component('Intelligence/Area')->loadDeferredProps(function (Assert $reload) use (&$json): void {
                $json = json_encode($reload->toArray(), JSON_THROW_ON_ERROR);
            });
        });

    expect($json)->toContain('workforce_figures')
        ->not->toContain('Person 0')
        ->not->toContain('SR-E-')
        ->not->toContain('Private Submitter')
        ->not->toContain('+251911000000');
});

it('is forbidden to a technical-only viewer', function (): void {
    $this->actingAs($this->s->viewer([IntelligencePermissions::VIEW, IntelligencePermissions::SYSTEM_HEALTH], 'bureau'))
        ->get(route('intelligence.area', 'workforce'))
        ->assertForbidden();
});
