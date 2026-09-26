<?php

declare(strict_types=1);

use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Services\Cafeteria\CafeteriaQrScanService;
use Illuminate\Support\Carbon;
use Tests\Support\CafeteriaScenario;

/*
 * The reference case (docs/cafeteria-network-access.md):
 *   Provider A runs Cafeteria 1 (main, primarily Org 1) and Cafeteria 2 (branch).
 *   Org 1 policy: 120 ETB. Org 2 policy: 150 ETB, primary Cafeteria 2, cross-location on.
 *   Employee X (Org 2) eats at Cafeteria 1.
 */
beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->org1 = $this->s->organization('Organization 1');
    $this->org2 = $this->s->organization('Organization 2');
    $this->s->main->forceFill(['organization_id' => $this->org1->id])->save();

    $this->s->enroll($this->org1, '120.00', $this->s->main);
    $this->s->enroll($this->org2, '150.00', $this->s->branch, crossLocation: true);

    $this->scan = fn ($card, $cafeteria, string $at, array $options = []) => app(CafeteriaQrScanService::class)
        ->process($card, $cafeteria, Carbon::parse($at), options: $options);
});

test('13-17 an org 2 employee eats at org 1 main cafeteria under org 2 policy, billed to org 2, paid to provider A', function (): void {
    [, $card] = $this->s->employee($this->org2);

    $result = ($this->scan)($card, $this->s->main, '2026-09-21 12:00');

    expect($result['allowed'])->toBeTrue();
    $transaction = CafeteriaTransaction::query()->sole();
    expect($transaction->employee_organization_id)->toBe($this->org2->id)       // billing owner
        ->and($transaction->cafeteria_provider_id)->toBe($this->s->main->id)    // service location
        ->and($transaction->provider_id)->toBe($this->s->provider->id)          // payee
        ->and($transaction->cafeteria_service_network_id)->toBe($this->s->network->id)
        ->and((string) $transaction->subsidy_amount_applied)->toBe('150.00')    // org 2 policy, not 120
        ->and($transaction->pricing_source)->toBe('policy')
        ->and($transaction->policy_snapshot['daily_subsidy_amount'])->toBe('150.00');
});

test('18-20 org 1 and org 2 employees at the same main cafeteria get their own subsidies', function (): void {
    [, $card1] = $this->s->employee($this->org1);
    [, $card2] = $this->s->employee($this->org2);

    $one = ($this->scan)($card1, $this->s->main, '2026-09-21 12:00');
    $two = ($this->scan)($card2, $this->s->main, '2026-09-21 12:05');

    expect($one['subsidy_applied'])->toBe(120.0)->and($two['subsidy_applied'])->toBe(150.0)
        ->and($one['employee_organization_id'])->toBe($this->org1->id)
        ->and($two['employee_organization_id'])->toBe($this->org2->id);
});

test('21 the cafeteria primary organization does not decide the subsidy', function (): void {
    // Cafeteria 1 primarily serves Org 1 (120), yet an Org 2 employee gets 150 there.
    [, $card] = $this->s->employee($this->org2);

    expect(($this->scan)($card, $this->s->main, '2026-09-21 12:00')['subsidy_applied'])->toBe(150.0);
});

test('22-25 a morning branch meal blocks the same entitlement at the main cafeteria later', function (): void {
    [, $card] = $this->s->employee($this->org2);

    $morning = ($this->scan)($card, $this->s->branch, '2026-09-21 08:00');
    $noon = ($this->scan)($card, $this->s->main, '2026-09-21 12:00');

    expect($morning['allowed'])->toBeTrue()
        ->and($noon['allowed'])->toBeFalse()
        ->and($noon['denial_reason'])->toBe('already_scanned_today')
        ->and(CafeteriaTransaction::query()->count())->toBe(1)
        ->and(CafeteriaTransactionConsumedDay::query()->whereNotNull('active_key')->count())->toBe(1);
});
