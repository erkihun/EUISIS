<?php

declare(strict_types=1);

use App\Enums\Transport\TransportVerificationMethod;
use App\Models\TransportInvoice;
use App\Services\Transport\Boarding\BoardingRequest;
use App\Services\Transport\Boarding\TransportBoardingService;
use App\Services\Transport\Settlement\TransportInvoiceService;
use App\Services\Transport\Settlement\TransportSettlementService;
use App\Services\Transport\TransportReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TransportScenario;

/*
 * Isolation and least privilege across the new surfaces: provider A never
 * reaches provider B's trips, devices, invoices or statements; a client
 * cannot grant itself a provider, a status or a settlement by posting them;
 * the manual-exception lookup needs its own grant; staff stay in scope.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 07:10'));
    $this->s = TransportScenario::make();
    $this->mine = $this->s->provider('Mine');
    $this->theirs = $this->s->provider('Theirs');
    $keys = ['provider.transport.trips.manage', 'provider.transport.dispatch', 'provider.transport.manifests.view', 'provider.transport.exceptions.view', 'provider.transport.devices.view', 'provider.transport.invoices.manage', 'provider.transport.scan'];
    $this->me = $this->s->portalUser($this->mine, 'manager', $keys);
    $this->them = $this->s->portalUser($this->theirs, 'manager', $keys);
    $this->theirRoute = $this->s->route($this->theirs);
    $this->theirTrip = $this->s->trip($this->theirRoute);
    [$this->theirDevice] = $this->s->device($this->theirs);
    $this->theirInvoice = app(TransportInvoiceService::class)->draft($this->theirs, ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'claimed_amount' => '100.00', 'invoice_number' => null], $this->them);
    $this->as = fn ($user) => $this->actingAs($user, 'provider');
});

it("never shows or drives another provider's trip, manifest, devices or exceptions", function (): void {
    ($this->as)($this->me)->get(route('provider.portal.transport.trips.show', $this->theirTrip))->assertForbidden();
    ($this->as)($this->me)->post(route('provider.portal.transport.trips.dispatch', $this->theirTrip), ['action' => 'complete'])->assertForbidden();
    ($this->as)($this->me)->get(route('provider.portal.transport.trips.manifest.pdf', $this->theirTrip))->assertForbidden();
    ($this->as)($this->me)->post(route('provider.portal.transport.devices.revoke', $this->theirDevice), ['reason' => 'lost'])->assertForbidden();
    expect($this->theirTrip->fresh()->status->value)->toBe('boarding')->and($this->theirDevice->fresh()->status->value)->toBe('active');

    // The exception list is filtered by provider, never by a client-supplied id.
    ($this->as)($this->me)->get(route('provider.portal.transport.exceptions.index', ['provider_id' => $this->theirs->id, 'status' => 'all']))
        ->assertOk()->assertInertia(fn ($page) => $page->where('exceptions.meta.total', 0));
});

it("never shows, edits or submits another provider's invoice, and never shows a statement before review", function (): void {
    foreach (['show', 'edit'] as $action) {
        ($this->as)($this->me)->get(route("provider.portal.transport.invoices.{$action}", $this->theirInvoice))->assertForbidden();
    }
    ($this->as)($this->me)->post(route('provider.portal.transport.invoices.submit', $this->theirInvoice))->assertForbidden();
    ($this->as)($this->me)->patch(route('provider.portal.transport.invoices.update', $this->theirInvoice), ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'claimed_amount' => '1.00'])->assertForbidden();
    expect((string) $this->theirInvoice->fresh()->claimed_amount)->toBe('100.00')->and($this->theirInvoice->fresh()->status->value)->toBe('draft');

    $finance = $this->s->staff(['transport-settlements.manage']);
    $draft = app(TransportSettlementService::class)->compute($this->theirs, $this->s->contract($this->theirs), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $finance);
    ($this->as)($this->them)->get(route('provider.portal.transport.settlements.show', $draft))->assertNotFound();
    ($this->as)($this->me)->get(route('provider.portal.transport.settlements.show', $draft))->assertForbidden();
    app(TransportSettlementService::class)->submitForReview($draft, $finance);
    ($this->as)($this->them)->get(route('provider.portal.transport.settlements.show', $draft))->assertOk();
    ($this->as)($this->me)->get(route('provider.portal.transport.settlements.show', $draft))->assertForbidden();
    ($this->as)($this->me)->post(route('provider.portal.transport.settlements.disputes.store', $draft), ['amount' => '10.00', 'reason' => 'not my statement'])->assertForbidden();
});

it('ignores a provider, status or settlement a client posts with an invoice', function (): void {
    ($this->as)($this->me)->post(route('provider.portal.transport.invoices.store'), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'claimed_amount' => '250.00', 'claimed_boardings' => 20,
        'provider_id' => $this->theirs->id, 'status' => 'approved', 'transport_settlement_id' => (string) Str::uuid(), 'reviewed_by' => (string) Str::uuid(),
    ])->assertRedirect();

    $invoice = TransportInvoice::query()->where('claimed_boardings', 20)->firstOrFail();
    expect($invoice->provider_id)->toBe($this->mine->id)->and($invoice->status->value)->toBe('draft')
        ->and($invoice->transport_settlement_id)->toBeNull()->and($invoice->reviewed_by)->toBeNull()
        ->and($invoice->transport_contract_id)->toBeNull();

    // A contract id must be the provider's own.
    ($this->as)($this->me)->post(route('provider.portal.transport.invoices.store'), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'claimed_amount' => '1.00', 'transport_contract_id' => $this->s->contract($this->theirs)->id,
    ])->assertSessionHasErrors('transport_contract_id');
});

it('opens the manual-exception lookup only to the grant that may record one, and answers nothing but id, number and name', function (): void {
    [$employee] = $this->s->employee('woreda_a1', 'Looked Up');
    $operator = $this->s->portalUser($this->mine, 'operator', ['provider.transport.scan']);
    $manual = $this->s->portalUser($this->mine, 'operator', ['provider.transport.scan', 'provider.transport.manual_exception']);

    ($this->as)($operator)->postJson(route('provider.portal.transport.scan.lookup'), ['employee_number' => $employee->employee_number])->assertForbidden();
    ($this->as)($manual)->postJson(route('provider.portal.transport.scan.lookup'), ['employee_number' => 'EMP-NOPE'])->assertNotFound();
    $hit = ($this->as)($manual)->postJson(route('provider.portal.transport.scan.lookup'), ['employee_number' => $employee->employee_number])->assertOk()->json();
    expect(array_keys($hit))->toBe(['id', 'employee_number', 'full_name']);

    $this->actingAs($this->s->staff(['transport-scan.create']))->postJson(route('transport.scan.lookup'), ['employee_number' => $employee->employee_number])->assertForbidden();
    $this->actingAs($this->s->staff(['transport-scan.create', 'transport-scan.manual']))->postJson(route('transport.scan.lookup'), ['employee_number' => $employee->employee_number])->assertOk();
});

it("keeps a scoped officer out of another organization's statement and lets them read their own", function (): void {
    $finance = $this->s->staff(['transport-settlements.manage']);
    $settlements = app(TransportSettlementService::class);
    $forB = $settlements->compute($this->mine, $this->s->contract($this->mine, overrides: ['organization_id' => $this->s->org['subcity_b']->id]), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $finance);
    $forA = $settlements->compute($this->mine, $this->s->contract($this->mine, overrides: ['organization_id' => $this->s->org['subcity_a']->id]), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $finance);
    $officer = $this->s->staff(['transport-settlements.view', 'transport-settlements.viewAny'], 'subcity_a');

    $this->actingAs($officer)->get(route('transport.settlements.show', $forB))->assertForbidden();
    $this->actingAs($officer)->get(route('transport.settlements.pdf', $forB))->assertForbidden();
    $this->actingAs($officer)->get(route('transport.settlements.show', $forA))->assertOk();
    $this->actingAs($officer)->get(route('transport.settlements.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('settlements.meta.total', 1));
});

it('voids only within scope and never a settled boarding, and the manifest keeps the voided row', function (): void {
    $route = $this->s->route($this->mine);
    $trip = $this->s->trip($route);
    [$employee, $card] = $this->s->employee('woreda_a1');
    $this->s->pass($employee, $this->mine, $route);
    $decision = app(TransportBoardingService::class)->board(new BoardingRequest(provider: $this->mine, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
        credential: $this->s->qr($card), tripId: $trip->id, idempotencyKey: (string) Str::uuid(), providerUser: $this->me));
    $transaction = $decision->transaction;

    $this->actingAs($this->s->staff(['transport-transactions.void'], 'subcity_b'))->post(route('transport.transactions.void', $transaction), ['reason' => 'Scanned by mistake'])->assertForbidden();
    $this->actingAs($this->s->staff(['transport-transactions.view', 'transport-transactions.viewAny'], 'subcity_a'))->post(route('transport.transactions.void', $transaction), ['reason' => 'Scanned by mistake'])->assertForbidden();
    expect($transaction->fresh()->voided_at)->toBeNull();

    $this->actingAs($this->s->staff(['transport-transactions.void'], 'subcity_a'))->post(route('transport.transactions.void', $transaction), ['reason' => 'Scanned by mistake'])->assertRedirect();
    expect($transaction->fresh()->voided_at)->not->toBeNull()->and($trip->fresh()->boarded_count)->toBe(0);

    $manifest = app(TransportReportService::class)->manifest($trip, staffTier: true);
    expect($manifest['rows'][0]['status'])->toBe('voided')->and($manifest['totals']['voided'])->toBe(1)->and($manifest['totals']['accepted'])->toBe(0);
});
