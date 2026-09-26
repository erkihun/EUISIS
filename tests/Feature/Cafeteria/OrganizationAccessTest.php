<?php

declare(strict_types=1);

use App\Models\OrganizationCafeteriaLocationAccess;
use App\Services\Cafeteria\CafeteriaQrScanService;
use Illuminate\Support\Carbon;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Organization 2');
    $this->scan = fn ($card, $cafeteria, string $at = '2026-09-21 12:00') => app(CafeteriaQrScanService::class)
        ->process($card, $cafeteria, Carbon::parse($at));
});

test('7 an organization can have a primary cafeteria', function (): void {
    $this->s->enroll($this->org, '150.00', $this->s->branch, crossLocation: false);
    [, $card] = $this->s->employee($this->org);

    expect(($this->scan)($card, $this->s->branch)['allowed'])->toBeTrue();
});

test('8 with cross-location usage disabled only the primary cafeteria serves', function (): void {
    $this->s->enroll($this->org, '150.00', $this->s->branch, crossLocation: false);
    [, $card] = $this->s->employee($this->org);

    expect(($this->scan)($card, $this->s->main)['denial_reason'])->toBe('location_not_allowed');
});

test('9 with cross-location usage enabled another location in the network serves', function (): void {
    $this->s->enroll($this->org, '150.00', $this->s->branch, crossLocation: true);
    [, $card] = $this->s->employee($this->org);

    expect(($this->scan)($card, $this->s->main)['allowed'])->toBeTrue();
});

test('10 the same policy terms without explicit access do not allow use', function (): void {
    // Org 1 has access and a 150 policy; Org 3 has an identical policy and assignment, but no access.
    $this->s->enroll($this->org, '150.00', $this->s->main);
    $org3 = $this->s->organization('Organization 3');
    $this->s->policy($org3, '150.00');
    [, $card] = $this->s->employee($org3);

    expect(($this->scan)($card, $this->s->main)['denial_reason'])->toBe('no_cafeteria_access');
});

test('11 expired access blocks use', function (): void {
    $this->s->grantAccess($this->org, $this->s->main, true, ['effective_to' => '2026-08-31']);
    $this->s->policy($this->org, '150.00');
    [, $card] = $this->s->employee($this->org);

    expect(($this->scan)($card, $this->s->main)['denial_reason'])->toBe('no_cafeteria_access');
});

test('12 location exceptions are respected both ways', function (): void {
    // Network-wide access, but the main cafeteria is excluded.
    $access = $this->s->grantAccess($this->org, $this->s->branch, crossLocation: true);
    $this->s->policy($this->org, '150.00');
    OrganizationCafeteriaLocationAccess::query()->create([
        'organization_cafeteria_access_id' => $access->id, 'cafeteria_id' => $this->s->main->id,
        'is_allowed' => false, 'effective_from' => '2026-01-01',
    ]);
    [, $card] = $this->s->employee($this->org);
    expect(($this->scan)($card, $this->s->main)['denial_reason'])->toBe('location_not_allowed');

    // Primary only, plus one explicitly allowed extra location.
    $access->forceFill(['allow_cross_location_usage' => false])->save();
    $access->locationExceptions()->update(['is_allowed' => true]);
    expect(($this->scan)($card, $this->s->main, '2026-09-22 12:00')['allowed'])->toBeTrue();
});
