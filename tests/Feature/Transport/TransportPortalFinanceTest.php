<?php

declare(strict_types=1);

use App\Models\TransportInvoice;
use App\Models\TransportSettlementAdjustment;
use App\Services\Transport\Settlement\TransportSettlementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TransportScenario;

/*
 * The provider's side of settlement: a claim is drafted, submitted and
 * withdrawn by the provider; the statement is the city's; the provider
 * reads it and may dispute a line until it is final.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-11-03 09:00'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->contract = $this->s->contract($this->provider);
    $this->manager = $this->s->portalUser($this->provider, 'manager', ['provider.transport.invoices.manage', 'provider.transport.trips.manage', 'provider.transport.exceptions.view', 'provider.transport.devices.view']);
    $this->finance = $this->s->staff(['transport-settlements.manage', 'transport-settlements.approve']);
    $this->as = fn () => $this->actingAs($this->manager, 'provider');
});

it('opens the new portal pages for a manager', function (string $name): void {
    ($this->as)()->get(route($name))->assertOk();
})->with(['provider.portal.transport.invoices.index', 'provider.portal.transport.invoices.create', 'provider.portal.transport.settlements.index', 'provider.portal.transport.exceptions.index', 'provider.portal.transport.devices.index', 'provider.portal.transport.trips.index']);

it('drafts, edits, submits and withdraws a claim, with the attachment kept privately', function (): void {
    ($this->as)()->post(route('provider.portal.transport.invoices.store'), [
        'transport_contract_id' => $this->contract->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'claimed_boardings' => 120, 'claimed_amount' => '1500.00', 'notes' => 'October', 'attachment' => UploadedFile::fake()->create('october.pdf', 120, 'application/pdf'),
    ])->assertRedirect();
    $invoice = TransportInvoice::query()->firstOrFail();
    expect($invoice->status->value)->toBe('draft')->and($invoice->invoice_number)->not->toBeEmpty()->and($invoice->attachment_path)->toStartWith('transport/invoices/');
    Storage::disk('local')->assertExists($invoice->attachment_path);

    ($this->as)()->patch(route('provider.portal.transport.invoices.update', $invoice), ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_boardings' => 125, 'claimed_amount' => '1562.50'])->assertRedirect();
    expect($invoice->fresh()->claimed_boardings)->toBe(125)->and((string) $invoice->fresh()->claimed_amount)->toBe('1562.50');

    ($this->as)()->post(route('provider.portal.transport.invoices.submit', $invoice))->assertRedirect();
    expect($invoice->fresh()->status->value)->toBe('submitted')->and($invoice->fresh()->submitted_at)->not->toBeNull();
    // Submitted: no longer editable by the provider.
    ($this->as)()->get(route('provider.portal.transport.invoices.edit', $invoice))->assertForbidden();
    ($this->as)()->patch(route('provider.portal.transport.invoices.update', $invoice), ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_amount' => '1.00'])->assertRedirect();
    expect((string) $invoice->fresh()->claimed_amount)->toBe('1562.50');

    ($this->as)()->post(route('provider.portal.transport.invoices.withdraw', $invoice))->assertRedirect();
    expect($invoice->fresh()->status->value)->toBe('withdrawn');
    ($this->as)()->get(route('provider.portal.transport.invoices.show', $invoice))->assertOk();
});

it('rejects a claim period that ends before it starts and an unreasonable amount', function (): void {
    ($this->as)()->post(route('provider.portal.transport.invoices.store'), ['period_start' => '2026-10-31', 'period_end' => '2026-10-01', 'claimed_amount' => '10.00'])->assertSessionHasErrors('period_end');
    ($this->as)()->post(route('provider.portal.transport.invoices.store'), ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_amount' => '-5'])->assertSessionHasErrors('claimed_amount');
    ($this->as)()->post(route('provider.portal.transport.invoices.store'), ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_amount' => '10.555'])->assertSessionHasErrors('claimed_amount');
});

it('lets the provider dispute a statement under review, marks the claim disputed, and locks disputes once final', function (): void {
    $settlements = app(TransportSettlementService::class);
    ($this->as)()->post(route('provider.portal.transport.invoices.store'), ['transport_contract_id' => $this->contract->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'claimed_boardings' => 40, 'claimed_amount' => '500.00']);
    $invoice = TransportInvoice::query()->firstOrFail();
    ($this->as)()->post(route('provider.portal.transport.invoices.submit', $invoice));
    $settlement = $settlements->compute($this->provider, $this->contract, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $this->finance, $invoice);

    // Draft: invisible to the provider.
    ($this->as)()->get(route('provider.portal.transport.settlements.index'))->assertOk()->assertInertia(fn ($page) => $page->where('settlements.meta.total', 0));
    $settlements->submitForReview($settlement, $this->finance);
    ($this->as)()->get(route('provider.portal.transport.settlements.show', $settlement))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canDispute', true)->where('reconciliation.claimed_boardings', 40)->where('reconciliation.verified_boardings', 0));
    ($this->as)()->get(route('provider.portal.transport.settlements.pdf', $settlement))->assertOk();

    ($this->as)()->post(route('provider.portal.transport.settlements.disputes.store', $settlement), [
        'dispute_type' => 'missing_transaction', 'amount' => '125.00', 'quantity' => 10, 'reason' => 'Ten riders on 12 October were not scanned because the device battery died.',
        'evidence' => UploadedFile::fake()->image('battery.jpg'),
    ])->assertRedirect()->assertSessionHas('flash.type', 'success');
    $dispute = TransportSettlementAdjustment::query()->firstOrFail();
    expect($dispute->origin->value)->toBe('provider_dispute')->and($dispute->status->value)->toBe('pending')->and((string) $dispute->amount)->toBe('125.00')
        ->and($dispute->requested_by_provider_user_id)->toBe($this->manager->id)->and($dispute->evidence_path)->toStartWith('transport/disputes/')
        ->and($invoice->fresh()->status->value)->toBe('disputed');

    // The city decides it; the provider cannot. A short reason is refused.
    ($this->as)()->post(route('provider.portal.transport.settlements.disputes.store', $settlement), ['amount' => '1.00', 'reason' => 'no'])->assertSessionHasErrors('reason');
    $approver = $this->s->staff(['transport-settlements.approve']);
    $settlements->decideAdjustment($dispute, false, $approver, 'Manifest shows the device online all day');
    $settlements->approve($settlement->fresh(), $approver);
    ($this->as)()->post(route('provider.portal.transport.settlements.disputes.store', $settlement), ['amount' => '1.00', 'reason' => 'Still disagree with the count'])->assertRedirect()->assertSessionHas('flash.type', 'error');
    expect(TransportSettlementAdjustment::query()->count())->toBe(1);
});

it('reports its own lost device and reads its own exceptions only', function (): void {
    [$device] = $this->s->device($this->provider);
    ($this->as)()->post(route('provider.portal.transport.devices.revoke', $device), ['reason' => 'Left on the bus'])->assertRedirect();
    expect($device->fresh()->status->value)->toBe('revoked')->and($device->fresh()->revocation_reason)->toBe('Left on the bus');
    ($this->as)()->get(route('provider.portal.transport.devices.index'))->assertOk()->assertInertia(fn ($page) => $page->has('devices', 1)->where('devices.0.status', 'revoked'));
});
