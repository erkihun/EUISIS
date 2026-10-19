<?php

declare(strict_types=1);

use App\Models\EmployeeServiceFeedback;
use App\Services\Intelligence\Contracts\TechnicalSignalSource;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use Carbon\CarbonImmutable;
use Tests\Support\IntelligenceBusinessFixture as F;
use Tests\Support\SituationRoomScenario;

/*
 * Public Voice Intelligence: types and targets kept apart, a complaint
 * spike detected against the weekly baseline with its evidence, a drop
 * correlated with the technical domain only when it answers, scope
 * isolation, and no submitter or employee ever in a payload.
 */

beforeEach(function (): void {
    F::createPermissions();
    $this->s = SituationRoomScenario::build();
    $this->grants = [...F::viewerGrants(), IntelligencePermissions::PUBLIC_VOICE];
});

it('counts the three types apart and confines them to the viewer\'s subtree', function (): void {
    $this->s->submission($this->s->org['woreda_a1'], 'feedback');
    $this->s->submission($this->s->org['woreda_b1'], 'suggestion');

    $a = F::area(IntelligenceArea::PublicVoice, $this->s->viewer($this->grants, 'subcity_a'));
    $kpis = F::panel($a['panels'], 'voice_kpis');
    expect($a['has_errors'])->toBeFalse()
        ->and(F::kpi($kpis, 'complaints_received'))->toBe(6)
        ->and(F::kpi($kpis, 'feedback_received'))->toBe(1)
        ->and(F::kpi($kpis, 'tip_offs_received'))->toBe(0)
        ->and(F::kpi($kpis, 'complaints_open'))->toBe(6);

    $b = F::panel(F::area(IntelligenceArea::PublicVoice, $this->s->viewer($this->grants, 'subcity_b'))['panels'], 'voice_kpis');
    expect(F::kpi($b, 'complaints_received'))->toBe(4)->and(F::kpi($b, 'tip_offs_received'))->toBe(1)->and(F::kpi($b, 'feedback_received'))->toBe(0);

    $targets = F::panel($a['panels'], 'voice_targets');
    expect($targets['type'])->toBe('stacked')
        ->and(collect($targets['data']['series'])->firstWhere('key', 'organization')['values'])->toBe([1, 0, 6])
        ->and(collect($targets['data']['series'])->firstWhere('key', 'employee')['values'])->toBe([0, 0, 0]);
});

it('detects a complaint spike with its evidence, for the affected subtree only', function (): void {
    for ($i = 0; $i < 8; $i++) {
        $this->s->submission($this->s->org['woreda_a1'], 'complaint', ['submitted_at' => now()->subHours(2)]);
    }

    $insight = F::insight(F::area(IntelligenceArea::PublicVoice, $this->s->viewer($this->grants, 'subcity_a'))['insights'], 'complaint_spike');
    expect($insight)->not->toBeNull()
        ->and($insight['severity'])->toBe('high')
        ->and($insight['summary_params']->current)->toBeGreaterThanOrEqual(8)
        ->and(collect($insight['evidence'])->firstWhere('label.key', 'intelligence.evidence.count')['value'])->toBe($insight['summary_params']->current)
        ->and(collect($insight['evidence'])->firstWhere('label.key', 'intelligence.evidence.baseline_window'))->not->toBeNull()
        ->and($insight['change']['direction'])->toBe('up')
        ->and($insight['confidence'])->toBeNull();

    expect(F::insight(F::area(IntelligenceArea::PublicVoice, $this->s->viewer($this->grants, 'subcity_b'))['insights'], 'complaint_spike'))->toBeNull();
});

it('reports a complaint drop without a technical claim unless the technical domain knows of failures', function (): void {
    // Six complaints a week for the six weeks before this one, none this week (the fixture's own complaints are removed so the date of the run cannot matter).
    EmployeeServiceFeedback::query()->delete();
    $weekStart = CarbonImmutable::now()->subDays(6)->startOfDay();
    for ($w = 1; $w <= 6; $w++) {
        for ($i = 0; $i < 6; $i++) {
            $this->s->submission($this->s->org['woreda_a1'], 'complaint', ['submitted_at' => $weekStart->subWeeks($w)->addDays($i)->addHours(9)]);
        }
    }
    $viewer = $this->s->viewer($this->grants, 'subcity_a');
    $insights = F::area(IntelligenceArea::PublicVoice, $viewer)['insights'];
    $drop = F::insight($insights, 'complaint_drop');
    expect($drop)->not->toBeNull()
        ->and($drop['confidence'])->toBeNull()
        ->and(F::insight($insights, 'complaint_drop_with_failures'))->toBeNull()
        ->and(F::insight($insights, 'complaint_spike'))->toBeNull();

    app()->bind(TechnicalSignalSource::class, fn () => new class implements TechnicalSignalSource
    {
        public function failuresFor(string $routeGroup, CarbonImmutable $from, CarbonImmutable $to): array
        {
            return ['known' => true, 'requests' => 200, 'failures' => 30, 'failure_rate_percent' => 15.0, 'incidents' => 1];
        }
    });
    $insights = F::area(IntelligenceArea::PublicVoice, $viewer)['insights'];
    $correlated = F::insight($insights, 'complaint_drop_with_failures');
    expect($correlated)->not->toBeNull()
        ->and($correlated['confidence'])->toBe('medium')
        ->and($correlated['summary_params']->failure_rate)->toBe(15.0)
        ->and(collect($correlated['evidence'])->firstWhere('label.key', 'intelligence.evidence.route')['value'])->toBe('public_feedback')
        ->and(F::insight($insights, 'complaint_drop'))->toBeNull();
});

it('withholds everything from a viewer without any target grant', function (): void {
    $payload = F::area(IntelligenceArea::PublicVoice, $this->s->viewer([IntelligencePermissions::VIEW, IntelligencePermissions::PUBLIC_VOICE], 'subcity_a'));

    expect($payload['panels'])->toHaveCount(1)
        ->and($payload['panels'][0]['reason'])->toBe('NO_PERMISSION')
        ->and($payload['insights'])->toBe([]);
});

it('counts only the targets the viewer may list', function (): void {
    $this->s->submission($this->s->org['woreda_a1'], 'complaint', ['target_type' => 'employee', 'source_type' => 'employee_card_qr', 'organization_target_id' => null, 'employee_id' => $this->s->people['woreda_a1'][0]->id]);
    $employeeOnly = $this->s->viewer([IntelligencePermissions::VIEW, IntelligencePermissions::PUBLIC_VOICE, PublicFeedbackVisibility::EMPLOYEE_TARGET], 'subcity_a');

    $kpis = F::panel(F::area(IntelligenceArea::PublicVoice, $employeeOnly)['panels'], 'voice_kpis');
    expect(F::kpi($kpis, 'complaints_received'))->toBe(1);
});

it('never carries a submitter, a narrative or an employee', function (): void {
    $this->s->submission($this->s->org['woreda_a1'], 'complaint', ['target_type' => 'employee', 'source_type' => 'employee_card_qr', 'organization_target_id' => null, 'employee_id' => $this->s->people['woreda_a1'][0]->id]);
    $json = json_encode(F::area(IntelligenceArea::PublicVoice, $this->s->viewer($this->grants, 'bureau')), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('Private Submitter')
        ->not->toContain('Private narrative')
        ->not->toContain('+251911000000')
        ->not->toContain('Person 0')
        ->not->toContain('SR-E-');
});
