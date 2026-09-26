<?php

declare(strict_types=1);

use App\Actions\Cafeteria\ReverseCafeteriaTransactionAction;
use App\Enums\CafeteriaSettlementStatus;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Cafeteria\Settlement\CafeteriaSettlementService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->org1 = $this->s->organization('Organization 1');
    $this->org2 = $this->s->organization('Organization 2');
    $this->s->enroll($this->org1, '120.00', $this->s->main);
    $this->s->enroll($this->org2, '150.00', $this->s->branch, crossLocation: true);
    $this->settlements = app(CafeteriaSettlementService::class);
    $this->actor = User::factory()->create();
    $this->scan = fn ($card, $cafeteria, string $at) => app(CafeteriaQrScanService::class)->process($card, $cafeteria, Carbon::parse($at));
    $this->period = [Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')];
});

test('50-51 a provider settlement is split by employee organization and by cafeteria', function (): void {
    [, $a] = $this->s->employee($this->org1);
    [, $b] = $this->s->employee($this->org2);
    [, $c] = $this->s->employee($this->org2);
    ($this->scan)($a, $this->s->main, '2026-09-21 12:00');   // Org 1 at main
    ($this->scan)($b, $this->s->main, '2026-09-21 12:05');   // Org 2 at main
    ($this->scan)($c, $this->s->branch, '2026-09-21 12:10'); // Org 2 at branch

    $settlement = $this->settlements->createDraft($this->s->provider, ...[...$this->period, $this->actor]);
    $lines = $settlement->lines->map(fn ($line) => [$line->employee_organization_id, $line->cafeteria_id, (string) $line->subsidy_amount]);

    expect($lines->all())->toEqualCanonicalizing([
        [$this->org1->id, $this->s->main->id, '120.00'],
        [$this->org2->id, $this->s->main->id, '150.00'],
        [$this->org2->id, $this->s->branch->id, '150.00'],
    ])->and((string) $settlement->total_provider_amount)->toBe('420.00')
        ->and($settlement->provider_id)->toBe($this->s->provider->id);
});

test('49 52 settlements use the applied snapshot; a later policy change moves nothing', function (): void {
    [, $card] = $this->s->employee($this->org2);
    ($this->scan)($card, $this->s->main, '2026-09-21 12:00');

    // The policy rate changes after the meal was recorded.
    CafeteriaServicePolicy::query()->where('organization_id', $this->org2->id)->update(['daily_subsidy_amount' => '999.00', 'provider_price' => '999.00']);

    $settlement = $this->settlements->finalize($this->settlements->createDraft($this->s->provider, ...[...$this->period, $this->actor]), $this->actor);

    expect($settlement->status)->toBe(CafeteriaSettlementStatus::Finalized)
        ->and((string) $settlement->total_subsidy_amount)->toBe('150.00');

    // A finalized settlement is final: its transactions cannot be reversed or settled again.
    expect(fn () => app(ReverseCafeteriaTransactionAction::class)->execute(CafeteriaTransaction::query()->sole(), $this->actor))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->settlements->createDraft($this->s->provider, ...[...$this->period, $this->actor]))
        ->toThrow(ValidationException::class);
});

test('a cancelled draft releases its transactions for a new settlement', function (): void {
    [, $card] = $this->s->employee($this->org2);
    ($this->scan)($card, $this->s->main, '2026-09-21 12:00');

    $this->settlements->cancel($this->settlements->createDraft($this->s->provider, ...[...$this->period, $this->actor]), $this->actor);

    expect($this->settlements->createDraft($this->s->provider, ...[...$this->period, $this->actor])->transaction_count)->toBe(1);
});

test('another provider cannot settle transactions it did not serve', function (): void {
    [, $card] = $this->s->employee($this->org2);
    ($this->scan)($card, $this->s->main, '2026-09-21 12:00');
    $other = CafeteriaScenario::make('B');

    expect(fn () => $this->settlements->createDraft($other->provider, ...[...$this->period, $this->actor]))->toThrow(ValidationException::class);
});
