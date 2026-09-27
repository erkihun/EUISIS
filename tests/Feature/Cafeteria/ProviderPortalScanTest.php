<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CafeteriaMenu;
use App\Models\CafeteriaProviderLedgerEntry;
use App\Models\CafeteriaTransaction;
use App\Models\ProviderUser;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\CafeteriaScenario;

/*
 * The counter path: a provider portal operator (provider guard, provider_users
 * table) scans a printed card through the portal, not an admin through the
 * back office.
 */
beforeEach(function (): void {
    config(['security.mfa_enforce' => false]);
    $this->travelTo(Carbon::parse('2026-09-21 12:00'));
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Organization 1');
    $this->s->enroll($this->org, '120.00', $this->s->main);
    [, $this->card] = $this->s->employee($this->org);
    $this->operator = ProviderUser::query()->create([
        'provider_id' => $this->s->provider->id, 'name' => 'Counter Operator', 'email' => 'counter@example.test', 'username' => 'counter',
        'password' => Hash::make('password'), 'provider_role' => 'operator', 'status' => 'active', 'portal_enabled' => true,
    ]);
    $this->scan = fn (string $nonce) => $this->actingAs($this->operator, 'provider')->post(route('provider.portal.scan.store'), [
        'provider_id' => $this->s->main->id,
        'qr_token' => app(CardQrPayloadService::class)->buildStableQrUrl($this->card),
        'scan_nonce' => $nonce, 'usage_mode' => 'single_day',
    ]);
});

it('records a portal scan, credits the cafeteria ledger and attributes neither to a staff user', function (): void {
    ($this->scan)((string) Str::uuid())->assertSessionHasNoErrors()->assertRedirect();

    $transaction = CafeteriaTransaction::query()->sole();
    $entry = CafeteriaProviderLedgerEntry::query()->sole();

    // created_by points at users (staff); a provider operator is not one.
    expect((string) $transaction->subsidy_amount_applied)->toBe('120.00')
        ->and($transaction->created_by)->toBeNull()
        ->and($transaction->metadata['scanned_by_provider_user_id'])->toBe($this->operator->id)
        ->and($entry->created_by)->toBeNull()
        ->and((string) $entry->credit)->toBe('120.00')
        ->and((float) $entry->balance_after)->toBe(120.0)
        ->and(AuditLog::query()->where('auditable_id', $transaction->id)->sole()->new_values['scanned_by_provider_user_id'])->toBe($this->operator->id);

    // A second employee's meal builds on the first.
    [, $second] = $this->s->employee($this->org);
    $this->card = $second;
    ($this->scan)((string) Str::uuid())->assertSessionHasNoErrors();
    expect(CafeteriaProviderLedgerEntry::query()->pluck('balance_after')->map(fn ($v) => (float) $v)->sort()->values()->all())->toBe([120.0, 240.0]);
});

it('lets a portal operator create, publish and close a menu', function (): void {
    $this->actingAs($this->operator, 'provider')->post(route('provider.portal.menus.store'), [
        'menu_date' => '2026-09-22', 'title_en' => 'Tuesday lunch', 'meal_type' => 'lunch', 'price' => '160.00',
        'items' => [['name_en' => 'Shiro']],
    ])->assertSessionHasNoErrors()->assertRedirect(route('provider.portal.menus.index'));

    $menu = CafeteriaMenu::query()->sole();
    $this->actingAs($this->operator, 'provider')->post(route('provider.portal.menus.publish', $menu))->assertSessionHasNoErrors();
    $this->actingAs($this->operator, 'provider')->post(route('provider.portal.menus.close', $menu))->assertSessionHasNoErrors();

    expect($menu->fresh())->status->toBe('closed')->created_by->toBeNull()->updated_by->toBeNull();
});

it('replays a repeated nonce without a second meal or ledger credit', function (): void {
    $nonce = (string) Str::uuid();
    ($this->scan)($nonce)->assertSessionHasNoErrors();
    ($this->scan)($nonce)->assertSessionHasNoErrors();

    expect(CafeteriaTransaction::query()->count())->toBe(1)
        ->and(CafeteriaProviderLedgerEntry::query()->count())->toBe(1);
});
