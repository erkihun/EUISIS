<?php

declare(strict_types=1);

use App\Actions\Cafeteria\ReverseCafeteriaTransactionAction;
use App\Enums\CafeteriaTransactionStatus;
use App\Models\AuditLog;
use App\Models\CafeteriaProviderAssignment;
use App\Models\CafeteriaSubsidyLedger;
use App\Models\CafeteriaTransaction;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\CafeteriaScenario;

/*
 * Back-office cafeteria work that no other test drove over HTTP: an attendant
 * scanning at the counter, the "today" feed, and reversing a meal.
 */
beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    $this->travelTo(Carbon::parse('2026-09-21 12:00'));
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Organization 1');
    $this->s->enroll($this->org, '120.00', $this->s->main);
    [$this->employee, $this->card] = $this->s->employee($this->org);
});

/** Staff member with the given permissions, assigned to the scenario's main cafeteria unless told otherwise. */
function cafeteriaStaff(object $test, array $permissions, bool $assigned = true): User
{
    $user = User::factory()->create(['status' => 'active', 'must_change_password' => false]);
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    if ($assigned) {
        CafeteriaProviderAssignment::query()->create(['cafeteria_provider_id' => $test->s->main->id, 'user_id' => $user->id, 'role' => 'operator', 'is_active' => true]);
    }

    return $user->fresh();
}

function counterScan(object $test, User $attendant, array $overrides = []): TestResponse
{
    return $test->actingAs($attendant)->post(route('cafeteria.scan.process'), [
        'provider_id' => $test->s->main->id,
        'qr_token' => app(CardQrPayloadService::class)->buildStableQrUrl($test->card),
        'scan_nonce' => (string) Str::uuid(),
        'usage_mode' => 'single_day',
        ...$overrides,
    ]);
}

it('records a counter scan by an attendant of that cafeteria and attributes it to them', function (): void {
    $attendant = cafeteriaStaff($this, ['cafeteria_transactions.scan', 'cafeteria_transactions.view']);

    counterScan($this, $attendant)->assertRedirect(route('cafeteria.scan'))->assertSessionHas('scan_result.allowed', true);

    $transaction = CafeteriaTransaction::query()->sole();
    expect($transaction->created_by)->toBe($attendant->id)
        ->and((string) $transaction->subsidy_amount_applied)->toBe('120.00');

    // The attendant's "today" feed lists it.
    $this->getJson(route('cafeteria.scan.today', ['provider_id' => $this->s->main->id]))->assertOk()
        ->assertJsonPath('data.0.transaction_number', $transaction->transaction_number);
});

it('refuses a counter scan at a cafeteria the attendant is not assigned to, and any client-sent amount', function (): void {
    counterScan($this, cafeteriaStaff($this, ['cafeteria_transactions.scan'], assigned: false))->assertForbidden();
    counterScan($this, cafeteriaStaff($this, ['cafeteria_transactions.scan']), ['meal_amount' => '1.00'])->assertSessionHasErrors('meal_amount');
    counterScan($this, cafeteriaStaff($this, ['cafeteria_transactions.view']))->assertForbidden(); // no scan permission
    expect(CafeteriaTransaction::query()->count())->toBe(0);
});

it('reverses a meal through the page: frees the day, refunds the subsidy once, and is audited', function (): void {
    app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, now());
    $transaction = CafeteriaTransaction::query()->sole();
    $supervisor = cafeteriaStaff($this, ['cafeteria_transactions.view', 'cafeteria_transactions.reverse']);

    $this->actingAs($supervisor)->post(route('cafeteria.transactions.reverse', $transaction), ['reason' => 'Scanned by mistake'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($transaction->fresh()->status)->toBe(CafeteriaTransactionStatus::Reversed)
        ->and(CafeteriaSubsidyLedger::query()->where('cafeteria_transaction_id', $transaction->id)->where('entry_type', 'reversal')->count())->toBe(1)
        ->and(AuditLog::query()->where('event_type', 'cafeteria_transaction_reversed')->where('auditable_id', $transaction->id)->where('reason', 'Scanned by mistake')->exists())->toBeTrue()
        // The day is free again: the employee can eat.
        ->and(app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, now())['allowed'])->toBeTrue();

    // Reversing again is refused.
    $this->post(route('cafeteria.transactions.reverse', $transaction))->assertSessionHasErrors('transaction');
});

it('refuses reversal without the permission or outside the user\'s cafeterias', function (): void {
    app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, now());
    $transaction = CafeteriaTransaction::query()->sole();

    $this->actingAs(cafeteriaStaff($this, ['cafeteria_transactions.view']))->post(route('cafeteria.transactions.reverse', $transaction))->assertForbidden();
    $this->actingAs(cafeteriaStaff($this, ['cafeteria_transactions.view', 'cafeteria_transactions.reverse'], assigned: false))
        ->post(route('cafeteria.transactions.reverse', $transaction))->assertForbidden();
    expect($transaction->fresh()->status)->toBe(CafeteriaTransactionStatus::Accepted);
});

it('refunds only once when two people reverse the same meal at the same time', function (): void {
    app(CafeteriaQrScanService::class)->process($this->card, $this->s->main, now());
    $first = CafeteriaTransaction::query()->sole();
    $second = CafeteriaTransaction::query()->sole(); // both loaded before either saved
    $actor = cafeteriaStaff($this, ['cafeteria_transactions.reverse']);

    app(ReverseCafeteriaTransactionAction::class)->execute($first, $actor);

    expect(fn () => app(ReverseCafeteriaTransactionAction::class)->execute($second, $actor))->toThrow(ValidationException::class)
        ->and(CafeteriaSubsidyLedger::query()->where('cafeteria_transaction_id', $first->id)->where('entry_type', 'reversal')->count())->toBe(1);
});
