<?php

declare(strict_types=1);

use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligencePermissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\IntelligenceBusinessFixture as F;
use Tests\Support\SituationRoomScenario;

/*
 * The Overview: one headline per business area the viewer may open, nothing
 * for the others, and the top insights of those areas by severity.
 */

beforeEach(function (): void {
    F::createPermissions();
    $this->s = SituationRoomScenario::build();
});

it('composes one headline per permitted business area and nothing else', function (): void {
    $viewer = $this->s->viewer([...F::viewerGrants(), IntelligencePermissions::WORKFORCE, IntelligencePermissions::PUBLIC_VOICE], 'subcity_a');
    $payload = F::area(IntelligenceArea::Overview, $viewer);

    expect($payload['has_errors'])->toBeFalse()
        ->and(array_map(fn (array $p): string => $p['key'], $payload['panels']))->toBe(['workforce_headline', 'voice_headline'])
        ->and($payload['panels'][0]['type'])->toBe('kpis')
        ->and(F::kpi($payload['panels'][0], 'employee_count'))->toBe($this->s->headcount('subcity_a', 'woreda_a1', 'woreda_a2'))
        ->and(F::kpi($payload['panels'][1], 'complaints_received'))->toBe(6);

    $keys = array_map(fn (array $i): string => $i['key'], $payload['insights']);
    expect($keys)->toContain('assignment_anomalies')->and(count($payload['insights']))->toBeLessThanOrEqual(8);
});

it('is empty for a viewer with the launcher grant only, and lists every business headline for a full viewer', function (): void {
    $launcherOnly = F::area(IntelligenceArea::Overview, $this->s->viewer([IntelligencePermissions::VIEW], 'subcity_a'));
    expect($launcherOnly['panels'])->toBe([])->and($launcherOnly['insights'])->toBe([]);

    $full = F::area(IntelligenceArea::Overview, $this->s->viewer([...F::viewerGrants(), ...IntelligencePermissions::business()], 'bureau'));
    expect(array_map(fn (array $p): string => $p['key'], $full['panels']))
        ->toBe(['executive_kpis', 'workforce_headline', 'service_headline', 'voice_headline', 'epms_headline', 'forecast_headline', 'quality_headline'])
        ->and(F::panel($full['panels'], 'epms_headline')['reason'])->toBe('NO_CURRENT_CYCLE')
        ->and(F::panel($full['panels'], 'forecast_headline')['reason'])->toBe('NOT_CONFIGURED');
});

it('serves the overview over HTTP with its deferred result', function (): void {
    $json = '';
    $this->actingAs($this->s->viewer([...F::viewerGrants(), IntelligencePermissions::WORKFORCE], 'subcity_a'))
        ->get(route('intelligence.area', 'overview'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$json): void {
            $page->component('Intelligence/Area')->loadDeferredProps(function (Assert $reload) use (&$json): void {
                $json = json_encode($reload->toArray(), JSON_THROW_ON_ERROR);
            });
        });

    expect($json)->toContain('workforce_headline')->not->toContain('Person 0')->not->toContain('SR-E-');
});
