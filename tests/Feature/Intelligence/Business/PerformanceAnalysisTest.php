<?php

declare(strict_types=1);

use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceBusinessFixture as F;
use Tests\Support\SituationRoomScenario;

/*
 * Performance Intelligence: the real EPMS workflow statuses, overdue by the
 * cycle's own dates, scope isolation, honest notices without a current
 * cycle, and never a person's score.
 */

beforeEach(function (): void {
    F::createPermissions();
    $this->s = SituationRoomScenario::build();
    $this->grants = [...F::viewerGrants(), IntelligencePermissions::PERFORMANCE];
});

it('says NO_CURRENT_CYCLE when no cycle is current', function (): void {
    $payload = F::area(IntelligenceArea::Performance, $this->s->viewer($this->grants, 'subcity_a'));

    expect($payload['has_errors'])->toBeFalse()
        ->and(F::panel($payload['panels'], 'epms_kpis')['reason'])->toBe('NO_CURRENT_CYCLE')
        ->and($payload['insights'])->toBe([]);
});

it('counts agreements by workflow status, finds overdue steps by the cycle dates, inside the scope only', function (): void {
    $cycle = F::currentCycle(planningEnded: true);
    F::agreement($cycle, $this->s->people['woreda_a1'][0], $this->s->org['woreda_a1'], 'DRAFT');
    F::agreement($cycle, $this->s->people['woreda_a1'][1], $this->s->org['woreda_a1'], 'AGREED');
    F::agreement($cycle, $this->s->people['woreda_b1'][0], $this->s->org['woreda_b1'], 'PENDING_MANAGER_APPROVAL');

    $a = F::area(IntelligenceArea::Performance, $this->s->viewer($this->grants, 'subcity_a'));
    expect($a['has_errors'])->toBeFalse();
    $kpis = F::panel($a['panels'], 'epms_kpis');
    expect(F::kpi($kpis, 'agreements_in_place'))->toBe(1)
        ->and(F::kpi($kpis, 'agreement_coverage'))->toBe(round(100 / 6, 1));
    $overdue = F::panel($a['panels'], 'epms_overdue');
    expect(F::kpi($overdue, 'agreements_overdue'))->toBe(1)
        ->and(F::kpi($overdue, 'midyear_reviews_overdue'))->toBe(0);
    $agreements = F::panel($a['panels'], 'epms_agreements');
    $byStatus = array_combine(array_map(fn (array $c): string => $c['key'], $agreements['data']['categories']), $agreements['data']['series'][0]['values']);
    expect($byStatus['situationRoom.values.agreement_status.DRAFT'])->toBe(1)
        ->and($byStatus['situationRoom.values.agreement_status.AGREED'])->toBe(1)
        ->and($byStatus['situationRoom.values.agreement_status.PENDING_MANAGER_APPROVAL'])->toBe(0);

    $insight = F::insight($a['insights'], 'epms_overdue_steps');
    expect($insight)->not->toBeNull()
        ->and($insight['summary_params']->count)->toBe(1)
        ->and(collect($insight['evidence'])->firstWhere('label.key', 'intelligence.evidence.agreements_overdue')['value'])->toBe(1);

    $b = F::area(IntelligenceArea::Performance, $this->s->viewer($this->grants, 'subcity_b'));
    expect(F::kpi(F::panel($b['panels'], 'epms_kpis'), 'agreements_in_place'))->toBe(0)
        ->and(F::kpi(F::panel($b['panels'], 'epms_overdue'), 'agreements_overdue'))->toBe(1);
});

it('reports overdue steps as NEEDS_DATA when the cycle has no phase dates, and withholds small rating groups', function (): void {
    $cycle = F::currentCycle(planningEnded: false);
    DB::table('performance_cycles')->where('id', $cycle)->update(['planning_end_date' => null, 'midyear_review_end_date' => null, 'yearend_review_end_date' => null]);
    $agreement = F::agreement($cycle, $this->s->people['woreda_a1'][0], $this->s->org['woreda_a1'], 'FINALIZED');
    DB::table('performance_results')->insert([
        'id' => (string) Str::uuid(), 'employee_id' => $this->s->people['woreda_a1'][0]->id, 'cycle_id' => $cycle, 'agreement_id' => $agreement,
        'organization_id' => $this->s->org['woreda_a1']->id, 'revision_no' => 1, 'is_current' => true, 'results_score' => 88, 'results_weight' => 100,
        'competency_weight' => 0, 'calculated_score' => 88, 'final_score' => 88, 'rating_label_en' => 'Very good', 'status' => 'RELEASED',
        'calculated_at' => now(), 'released_at' => now(), 'snapshot_json' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $payload = F::area(IntelligenceArea::Performance, $this->s->viewer($this->grants, 'subcity_a'));
    expect(F::panel($payload['panels'], 'epms_overdue')['reason'])->toBe('NEEDS_DATA');
    $ratings = F::panel($payload['panels'], 'epms_ratings');
    expect($ratings['type'])->toBe('bar')->and($ratings['data']['series'][0]['values'])->toBe([null]);
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('final_score')->not->toContain('Person 0');
});
