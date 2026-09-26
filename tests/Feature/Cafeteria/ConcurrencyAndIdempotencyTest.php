<?php

declare(strict_types=1);

use App\Actions\Cafeteria\ReverseCafeteriaTransactionAction;
use App\Enums\CafeteriaUsageMode;
use App\Models\CafeteriaTransaction;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\Cafeteria\Policy\CafeteriaEntitlementService;
use App\Services\Cafeteria\Policy\CafeteriaPolicyResolver;
use App\Services\Cafeteria\Policy\CafeteriaTransactionPricingService;
use App\Services\Cafeteria\Policy\CafeteriaTransactionService;
use App\Services\Cafeteria\Policy\EntitlementAlreadyConsumed;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Organization 2');
    $this->s->enroll($this->org, '150.00', $this->s->branch, crossLocation: true);
    [$this->employee, $this->card] = $this->s->employee($this->org);
});

/** Both scanners check availability before either writes — the race the database must settle. */
function checkedScan(object $test, $cafeteria, string $at): array
{
    $time = Carbon::parse($at);
    $resolution = app(CafeteriaPolicyResolver::class)->resolve($test->employee, $cafeteria, $time);
    $decision = app(CafeteriaEntitlementService::class)->evaluate($test->employee, $cafeteria, $time, $resolution, CafeteriaUsageMode::SingleDay);
    $pricing = app(CafeteriaTransactionPricingService::class)->price($resolution->policy, count($decision->entitlements));

    return [$time, $resolution, $decision, $pricing];
}

test('38 simultaneous scans at the main cafeteria and a branch consume one entitlement', function (): void {
    $atBranch = checkedScan($this, $this->s->branch, '2026-09-21 12:00:00');
    $atMain = checkedScan($this, $this->s->main, '2026-09-21 12:00:01');
    expect($atBranch[2]->eligible)->toBeTrue()->and($atMain[2]->eligible)->toBeTrue();

    $record = fn (array $scan) => app(CafeteriaTransactionService::class)->record(
        $this->employee, $this->card, $scan[0], $scan[0], $scan[1], $scan[2], $scan[3], CafeteriaUsageMode::SingleDay,
    );

    $record($atBranch);
    expect(fn () => $record($atMain))->toThrow(EntitlementAlreadyConsumed::class);

    expect(CafeteriaTransaction::query()->count())->toBe(1)
        ->and(CafeteriaTransactionConsumedDay::query()->whereNotNull('active_key')->count())->toBe(1);
});

test('the database itself refuses a second claim on the same entitlement', function (): void {
    app(CafeteriaQrScanService::class)->process($this->card, $this->s->branch, Carbon::parse('2026-09-21 12:00'));
    $claim = CafeteriaTransactionConsumedDay::query()->sole();

    expect(fn () => CafeteriaTransactionConsumedDay::query()->create([
        ...$claim->only(['cafeteria_transaction_id', 'employee_id', 'consumed_date', 'entitlement_type', 'slot_no', 'active_key']),
        'consumed_at_cafeteria_id' => $this->s->main->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('39 a scanner retry with the same nonce is answered without a second transaction', function (): void {
    $nonce = (string) Str::uuid();
    $scan = fn () => app(CafeteriaQrScanService::class)->process($this->card, $this->s->branch, Carbon::parse('2026-09-21 12:00'), options: ['scan_nonce' => $nonce]);

    $first = $scan();
    $retry = $scan();

    expect($first['allowed'])->toBeTrue()
        ->and($retry['duplicate'])->toBeTrue()
        ->and($retry['transaction']->id)->toBe($first['transaction']->id)
        ->and(CafeteriaTransaction::query()->count())->toBe(1);
});

test('40 policy resolution is stable and deterministic', function (): void {
    $resolver = app(CafeteriaPolicyResolver::class);
    $at = Carbon::parse('2026-09-21 12:00');

    $ids = collect(range(1, 5))->map(fn () => $resolver->resolve($this->employee, $this->s->main, $at)->policy->id)->unique();

    expect($ids)->toHaveCount(1);
});

test('41 reversing a transaction frees its entitlement at every cafeteria', function (): void {
    $first = app(CafeteriaQrScanService::class)->process($this->card, $this->s->branch, Carbon::parse('2026-09-21 08:00'));
    app(ReverseCafeteriaTransactionAction::class)->execute($first['transaction'], User::factory()->create(), 'wrong card');

    $again = app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, Carbon::parse('2026-09-21 12:00'));

    expect($again['allowed'])->toBeTrue()
        ->and(CafeteriaTransactionConsumedDay::query()->where('status', 'reversed')->whereNull('active_key')->count())->toBe(1)
        ->and(CafeteriaTransactionConsumedDay::query()->whereNotNull('active_key')->count())->toBe(1);
});
