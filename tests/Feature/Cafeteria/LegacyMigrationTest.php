<?php

declare(strict_types=1);

use App\Enums\CafeteriaPolicyStatus;
use App\Models\CafeteriaServicePolicy;
use App\Models\CafeteriaSubsidyRule;
use App\Models\CafeteriaTransactionConsumedDay;
use App\Models\User;
use App\Services\Cafeteria\CafeteriaQrScanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CafeteriaScenario;

beforeEach(function (): void {
    $this->s = CafeteriaScenario::make();
    $this->org = $this->s->organization('Legacy Organization');
});

test('a legacy global subsidy never becomes a policy and scans stay blocked without one', function (): void {
    CafeteriaSubsidyRule::query()->create(['code' => 'GLOBAL', 'name_en' => 'Global', 'subsidy_amount' => 40, 'currency' => 'ETB',
        'effective_from' => '2026-01-01', 'applies_to' => 'all_employees', 'is_active' => true]);
    $this->s->grantAccess($this->org, $this->s->main, true);
    $this->s->assign($this->org);
    [, $card] = $this->s->employee($this->org);

    $this->artisan('cafeteria:legacy-report', ['--create-drafts' => true, '--actor' => User::factory()->create()->id])
        ->expectsOutputToContain('NEEDS_DECISION')
        ->assertSuccessful();

    expect(CafeteriaServicePolicy::query()->count())->toBe(0);
    $scan = app(CafeteriaQrScanService::class)->process($card, $this->s->main, Carbon::parse('2026-09-21 12:00'));
    expect($scan['denial_reason'])->toBe('no_active_policy')
        ->and($scan['denial_message'])->toBe('No active cafeteria service policy is configured for your organization and this cafeteria.');
});

test('an organization-specific legacy rule is only drafted, never activated', function (): void {
    CafeteriaSubsidyRule::query()->create(['code' => 'ORG', 'name_en' => 'Org rule', 'subsidy_amount' => 55, 'currency' => 'ETB',
        'effective_from' => '2026-01-01', 'applies_to' => 'organization', 'organization_id' => $this->org->id, 'is_active' => true]);
    $this->s->grantAccess($this->org, $this->s->main, true);
    $this->s->assign($this->org);
    [, $card] = $this->s->employee($this->org);

    $this->artisan('cafeteria:legacy-report', ['--create-drafts' => true, '--actor' => User::factory()->create()->id])->assertSuccessful();

    $draft = CafeteriaServicePolicy::query()->sole();
    expect($draft->status)->toBe(CafeteriaPolicyStatus::Draft)
        ->and((string) $draft->daily_subsidy_amount)->toBe('55.00');
    // A draft does not bind: the scan is still refused until someone approves it.
    expect(app(CafeteriaQrScanService::class)->process($card, $this->s->main, Carbon::parse('2026-09-21 12:00'))['denial_reason'])->toBe('no_active_policy');
});

test('the migration backfill keeps legacy money, dates the billing organization and flags historic double use', function (): void {
    [$employee, $card] = $this->s->employee($this->org, '2026-01-01', '2026-06-30');
    $later = $this->s->organization('Later Organization');
    $this->s->assignEmployee($employee, $later, '2026-07-01');

    $transactionId = (string) Str::uuid7();
    $serviceTransactionId = (string) Str::uuid7();
    DB::table('service_transactions')->insert([
        'id' => $serviceTransactionId, 'employee_id' => $employee->id, 'service_type_id' => DB::table('service_types')->where('code', 'cafeteria')->value('id'),
        'service_provider_id' => $this->s->main->service_provider_id, 'status' => 'authorized', 'occurred_at' => '2026-03-02 12:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('cafeteria_transactions')->insert([
        'id' => $transactionId, 'service_transaction_id' => $serviceTransactionId, 'transaction_number' => 'LEGACY-1', 'employee_id' => $employee->id, 'id_card_id' => $card->id,
        'cafeteria_provider_id' => $this->s->main->id, 'transaction_date' => '2026-03-02', 'scanned_at' => '2026-03-02 12:00:00',
        'meal_amount' => '40.00', 'subsidy_amount_applied' => '40.00', 'employee_payable_amount' => '0.00', 'status' => 'accepted',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['2026-03-02', '2026-03-02', '2026-03-03'] as $index => $date) {
        DB::table('cafeteria_transaction_consumed_days')->insert([
            'id' => (string) Str::uuid7(), 'cafeteria_transaction_id' => $transactionId, 'employee_id' => $employee->id,
            'consumed_date' => $date, 'subsidy_amount' => '40.00', 'source' => 'scan',
            'reversed_at' => $index === 2 ? now() : null,
            'created_at' => now()->addSeconds($index), 'updated_at' => now(),
        ]);
    }

    $migration = require database_path('migrations/2026_09_27_000300_add_entitlement_ledger_and_transaction_snapshots.php');
    foreach (['backfillTransactions', 'backfillEntitlementLedger'] as $method) {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    $transaction = DB::table('cafeteria_transactions')->where('id', $transactionId)->first();
    expect($transaction->employee_organization_id)->toBe($this->org->id)          // the March assignment, not today's
        ->and($transaction->provider_id)->toBe($this->s->provider->id)
        ->and($transaction->pricing_source)->toBe('legacy')
        ->and((float) $transaction->subsidy_amount_applied)->toBe(40.0)             // stored money untouched
        ->and($transaction->cafeteria_service_policy_id)->toBeNull();                // no policy invented for history

    $days = CafeteriaTransactionConsumedDay::query()->orderBy('created_at')->get();
    expect($days->pluck('status')->all())->toBe(['consumed', 'legacy_duplicate', 'reversed'])
        ->and($days->whereNotNull('active_key'))->toHaveCount(1);
});
