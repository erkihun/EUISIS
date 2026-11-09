<?php

use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** A value encrypted with a key this server does not have (an APP_KEY changed without APP_PREVIOUS_KEYS). */
function encryptedWithOtherKey(string $value): string
{
    return (new Encrypter(str_repeat('o', 32), config('app.cipher')))->encryptString($value);
}

test('values encrypted with an unknown key are reported, and only those are cleared', function (): void {
    $lost = User::factory()->create();
    $fine = User::factory()->create(['phone_number' => '+251911000000']);
    DB::table('users')->where('id', $lost->id)->update([
        'phone_number' => encryptedWithOtherKey('+251922000000'),
        'two_factor_secret' => encryptedWithOtherKey('SECRET'),
    ]);

    $this->artisan('security:encrypted-values')
        ->expectsOutputToContain('users.phone_number')
        ->assertFailed();

    $this->artisan('security:encrypted-values', ['--clear' => true, '--force' => true])->assertSuccessful();

    $lostRow = DB::table('users')->where('id', $lost->id)->first();
    expect($lostRow->phone_number)->toBeNull()
        ->and($lostRow->two_factor_secret)->toBeNull()
        ->and($fine->fresh()->phone_number)->toBe('+251911000000');

    $this->artisan('security:encrypted-values')->assertSuccessful();
});

test('a value encrypted with a key listed in APP_PREVIOUS_KEYS counts as readable', function (): void {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['phone_number' => encryptedWithOtherKey('+251933000000')]);

    config(['app.previous_keys' => ['base64:'.base64_encode(str_repeat('o', 32))]]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    $this->artisan('security:encrypted-values')->assertSuccessful();
    expect($user->fresh()->phone_number)->toBe('+251933000000');
});

test('plaintext values are reported but never cleared', function (): void {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['phone_number' => '0944000000']);

    $this->artisan('security:encrypted-values', ['--clear' => true, '--force' => true])->assertSuccessful();

    expect(DB::table('users')->where('id', $user->id)->value('phone_number'))->toBe('0944000000');
});
