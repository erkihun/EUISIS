<?php

use App\Models\Employee;
use App\Models\IdCard;
use App\Models\ServiceTerminal;
use App\Models\User;
use App\Services\Nfc\NfcCredentialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('prunes only challenges outside the retention window', function () {
    $actor = User::factory()->create();
    $employee = Employee::create(['employee_number' => 'PRUNE-EMP', 'first_name' => 'P', 'last_name' => 'R', 'full_name' => 'P R', 'status' => 'active']);
    $card = IdCard::create(['employee_id' => $employee->id, 'card_number' => 'PRUNE-CARD', 'status' => 'active', 'is_current' => true,
        'public_card_uuid' => (string) Str::uuid(), 'qr_status' => 'active', 'expires_at' => now()->addYear(), 'activated_at' => now()]);
    $credential = app(NfcCredentialService::class)->provision($card, $actor);
    $terminal = ServiceTerminal::create(['terminal_code' => 'PRUNE-1', 'name' => 'T', 'terminal_type' => 'verification', 'status' => 'active']);

    $rows = [
        ['nonce_hash' => str_repeat('a', 64), 'expires_at' => now()->subDays(3)],   // stale
        ['nonce_hash' => str_repeat('b', 64), 'expires_at' => now()->subHours(2)],  // expired, inside window
        ['nonce_hash' => str_repeat('c', 64), 'expires_at' => now()->addMinute()],  // live
    ];
    foreach ($rows as $row) {
        DB::table('nfc_challenges')->insert($row + [
            'nfc_credential_id' => $credential->id, 'terminal_id' => $terminal->id, 'context_hash' => str_repeat('0', 64),
        ]);
    }

    $this->artisan('nfc:prune-challenges', ['--hours' => 24])->assertSuccessful();

    expect(DB::table('nfc_challenges')->pluck('nonce_hash')->all())
        ->toEqualCanonicalizing([str_repeat('b', 64), str_repeat('c', 64)]);
});
