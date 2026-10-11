<?php

declare(strict_types=1);

use App\Services\Cafeteria\CafeteriaQrScanService;
use Illuminate\Support\Carbon;
use Tests\Support\CafeteriaScenario;

/*
 * Decision D-3: a QR that only ends in a card's primary key carries no secret
 * — card ids appear in admin URLs — so it is refused at the counter. Printed
 * cards carry the public card UUID (docs/id-card-integration-guide.md §3) and
 * every card has one (id-cards:backfill-public-uuids). An operator can accept
 * the old form during a supervised reprint with ID_CARD_ACCEPT_LEGACY_QR.
 */

beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Legacy QR Organization');
    $this->s->enroll($this->org, '120.00', $this->s->main);
    [, $this->card] = $this->s->employee($this->org);
    $this->scan = fn (string $value) => app(CafeteriaQrScanService::class)->process($value, $this->s->main, Carbon::parse('2026-09-21 12:00'));
});

it('refuses a QR that only ends in the card primary key', function (): void {
    $result = ($this->scan)('https://euisis.example/id-cards/'.$this->card->id);

    expect($result['allowed'])->toBeFalse()
        ->and($result['transaction'] ?? null)->toBeNull();
});

it('still accepts the printed public card reference', function (): void {
    $result = ($this->scan)('https://euisis.example/id-checker/'.$this->card->public_card_uuid);

    expect($result['allowed'])->toBeTrue();
});

it('accepts the legacy form only while the transition switch is on', function (): void {
    config(['id_cards.accept_legacy_qr' => true]);

    expect(($this->scan)('https://euisis.example/id-cards/'.$this->card->id)['allowed'])->toBeTrue();
});
