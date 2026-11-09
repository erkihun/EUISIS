<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\User;
use App\Support\Security\NationalIdHash;
use Illuminate\Support\Facades\DB;

/*
 * Decision D-1: the national-ID lookup hash is keyed (HMAC-SHA-256). An
 * unkeyed SHA-256 of a 16-digit number can be reversed by brute force from a
 * database copy alone; with a key, the hash reveals no more than the
 * encrypted national ID it sits beside.
 */

function nidEmployee(string $nationalId, string $number = 'NID-H-1'): Employee
{
    return Employee::query()->create([
        'employee_number' => $number, 'first_name' => 'Hash', 'last_name' => 'Test',
        'full_name' => 'Hash Test', 'status' => 'active', 'national_id' => $nationalId,
    ]);
}

it('stores a keyed hash, never the plain SHA-256 of the national id', function (): void {
    $hash = nidEmployee('1234567890123456')->fresh()->national_id_hash;

    expect($hash)->not->toBe(hash('sha256', '1234567890123456'))
        ->and($hash)->toBe(NationalIdHash::of('1234567890123456'))
        ->and(strlen((string) $hash))->toBe(64);
});

it('uses a dedicated key when one is configured', function (): void {
    $derived = NationalIdHash::of('1234567890123456');
    config(['security.national_id_hash_key' => 'a-dedicated-secret']);

    expect(NationalIdHash::of('1234567890123456'))
        ->not->toBe($derived)
        ->toBe(hash_hmac('sha256', '1234567890123456', 'a-dedicated-secret'));
});

it('hashes user national ids the same way', function (): void {
    $user = User::factory()->create(['national_id' => '9999888877776666']);

    expect($user->fresh()->national_id_hash)->toBe(NationalIdHash::of('9999888877776666'));
});

it('re-hashes rows stored with the old unkeyed hash', function (): void {
    $employee = nidEmployee('5555666677778888');
    DB::table('employees')->where('id', $employee->id)->update(['national_id_hash' => hash('sha256', '5555666677778888')]);
    $user = User::factory()->create(['national_id' => '4444333322221111']);
    DB::table('users')->where('id', $user->id)->update(['national_id_hash' => hash('sha256', '4444333322221111')]);

    $this->artisan('security:rehash-national-ids')->assertExitCode(0);

    expect(DB::table('employees')->where('id', $employee->id)->value('national_id_hash'))->toBe(NationalIdHash::of('5555666677778888'))
        ->and(DB::table('users')->where('id', $user->id)->value('national_id_hash'))->toBe(NationalIdHash::of('4444333322221111'));
});
