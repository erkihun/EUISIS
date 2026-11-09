<?php

declare(strict_types=1);

use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Services\SituationRoom\SituationPermissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SituationRoomScenario;

/*
 * Hierarchy roll-up and isolation (docs/situation-room-access-control.md).
 * Bureau → Subcity A (Woreda A1, A2) / Subcity B (Woreda B1): a parent sees
 * its descendants rolled up, a child never sees its sibling or its parent,
 * and a forged organization id is refused before anything is counted.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    $this->s = SituationRoomScenario::build();
});

/**
 * The deferred `result` prop of a Situation Room page, fetched the way the
 * browser does (an Inertia partial reload).
 *
 * @return array<string, mixed>
 */
function srResult(User $user, string $url, string $component): array
{
    $result = null;
    // A plain closure, not an arrow function: arrow functions capture by value.
    test()->actingAs($user)->get($url)->assertOk()->assertInertia(function (Assert $page) use ($component, &$result): void {
        $page->component($component)->loadDeferredProps(function (Assert $reload) use (&$result): void {
            $result = $reload->toArray()['props']['result'];
        });
    });

    return $result;
}

/** The page's immediate props (everything but the deferred payload). */
function srProps(User $user, string $url): array
{
    $props = null;
    test()->actingAs($user)->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** @return array<string, mixed> */
function srPanel(array $result, string $key): array
{
    foreach ($result['payload']['panels'] as $panel) {
        if ($panel['key'] === $key) {
            return $panel;
        }
    }

    throw new RuntimeException("Panel {$key} not in payload");
}

/** @return array<string, int|float|null> row label (en) => first series value */
function srRows(array $panel): array
{
    $rows = [];
    foreach ($panel['data']['categories'] as $i => $category) {
        $rows[$category['en'] ?? $category['key'] ?? (string) $i] = $panel['data']['series'][0]['values'][$i];
    }

    return $rows;
}

function srKpi(array $panel, string $key): mixed
{
    foreach ($panel['data']['items'] as $item) {
        if ($item['key'] === $key) {
            return $item['value'];
        }
    }

    throw new RuntimeException("KPI {$key} not in panel");
}

it('rolls a parent organization up into its direct children', function (): void {
    $viewer = $this->s->viewer([SituationPermissions::WORKFORCE], 'bureau');
    $result = srResult($viewer, route('situation-room.category', 'workforce'), 'SituationRoom/Category');

    expect(srRows(srPanel($result, 'headcount_by_organization')))->toBe([
        'Bureau (own office)' => 2,
        'Subcity A' => $this->s->headcount('subcity_a', 'woreda_a1', 'woreda_a2'),
        'Subcity B' => $this->s->headcount('subcity_b', 'woreda_b1'),
    ])->and(srKpi(srPanel($result, 'workforce_kpis'), 'headcount'))->toBe(15);
});

it('takes a viewer with one top organization one level down, and never shows a sibling', function (): void {
    $viewer = $this->s->viewer([SituationPermissions::WORKFORCE, SituationPermissions::PUBLIC_VOICE], 'subcity_a');
    $workforce = srResult($viewer, route('situation-room.category', 'workforce'), 'SituationRoom/Category');
    $complaints = srResult($viewer, route('situation-room.category', 'complaints'), 'SituationRoom/Category');

    expect(srRows(srPanel($workforce, 'headcount_by_organization')))->toBe(['Subcity A (own office)' => 1, 'Woreda A1' => 3, 'Woreda A2' => 2])
        ->and(srKpi(srPanel($workforce, 'workforce_kpis'), 'headcount'))->toBe(6)
        ->and(srRows(srPanel($complaints, 'complaints_by_organization')))->toBe(['Subcity A (own office)' => 1, 'Woreda A1' => 2, 'Woreda A2' => 3])
        ->and(srKpi(srPanel($complaints, 'complaints_kpis'), 'complaints_received'))->toBe(6);

    $json = json_encode([$workforce, $complaints]);
    expect($json)->not->toContain('Subcity B')->not->toContain('Woreda B1')->not->toContain('"Bureau');
});

it('confines a self-scoped woreda to itself', function (): void {
    $viewer = $this->s->viewer([SituationPermissions::WORKFORCE], 'woreda_a1', OrganizationScopeType::Self);
    $result = srResult($viewer, route('situation-room.category', 'workforce'), 'SituationRoom/Category');

    expect(srKpi(srPanel($result, 'workforce_kpis'), 'headcount'))->toBe(3)
        ->and(srRows(srPanel($result, 'headcount_by_organization')))->toBe(['Woreda A1 (own office)' => 3]);
});

it('narrows to a focused descendant inside the scope', function (): void {
    $viewer = $this->s->viewer([SituationPermissions::WORKFORCE], 'subcity_a');
    $result = srResult($viewer, route('situation-room.category', ['category' => 'workforce', 'organization_id' => $this->s->org['woreda_a2']->id]), 'SituationRoom/Category');

    expect(srKpi(srPanel($result, 'workforce_kpis'), 'headcount'))->toBe(2);
});

it('refuses a forged organization outside the scope before counting anything', function (string $category): void {
    $viewer = $this->s->viewer([SituationPermissions::WORKFORCE, SituationPermissions::PUBLIC_VOICE], 'subcity_a');

    $this->actingAs($viewer)->get(route('situation-room.category', ['category' => $category, 'organization_id' => $this->s->org['woreda_b1']->id]))->assertForbidden();
    $this->actingAs($viewer)->get(route('situation-room.category', ['category' => $category, 'organization_id' => $this->s->org['bureau']->id]))->assertForbidden();
})->with(['workforce', 'complaints', 'overview']);

it('gives an unrestricted viewer the whole city rolled up from the top', function (): void {
    $viewer = $this->s->viewer([SituationPermissions::PUBLIC_VOICE]);
    $result = srResult($viewer, route('situation-room.category', 'complaints'), 'SituationRoom/Category');

    expect(srRows(srPanel($result, 'complaints_by_organization')))->toBe(['Bureau (own office)' => 0, 'Subcity A' => 6, 'Subcity B' => 4])
        ->and(srKpi(srPanel($result, 'complaints_kpis'), 'complaints_received'))->toBe(10);
});

it('keeps submitter identity and narrative out of every public-voice payload', function (string $category): void {
    $this->s->submission($this->s->org['woreda_a1'], 'suggestion');
    $this->s->submission($this->s->org['woreda_a1'], 'feedback');
    $viewer = $this->s->viewer([SituationPermissions::PUBLIC_VOICE, SituationPermissions::EMPLOYEE_BREAKDOWN], 'bureau');
    $json = json_encode(srResult($viewer, route('situation-room.category', ['category' => $category, 'organization_id' => $this->s->org['woreda_a1']->id]), 'SituationRoom/Category'));

    expect($json)->not->toContain('Private Submitter')->not->toContain('+251911000000')->not->toContain('Private narrative');
})->with(['complaints', 'tip_offs', 'feedback', 'service_delivery', 'overview']);

it('opens a category only with its grant', function (): void {
    $plain = $this->s->viewer([], 'bureau');

    $this->actingAs($plain)->get(route('situation-room.index'))->assertOk();
    $this->actingAs($plain)->get(route('situation-room.category', 'workforce'))->assertForbidden();
    $this->actingAs($plain)->get(route('situation-room.category', 'cases'))->assertForbidden();
    $this->actingAs($plain)->get(route('situation-room.wall'))->assertForbidden();
    $this->actingAs($plain)->get(route('situation-room.reports'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('situation-room.index'))->assertForbidden();
});

it('lists only the categories a viewer may open', function (): void {
    $viewer = $this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'bureau');

    $keys = collect(srProps($viewer, route('situation-room.index'))['categories'])->pluck('key')->all();

    expect($keys)->toContain('overview', 'complaints', 'tip_offs', 'feedback', 'service_delivery', 'critical_attention')
        ->not->toContain('workforce', 'cafeteria', 'cases', 'performance', 'transport');
});
