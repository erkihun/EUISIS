<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * sensitive-data:encrypt-existing rewrites personal data in place, so it must
 * change nothing on a dry run, encrypt only what is still plaintext, keep the
 * lookup hash right, and be safe to run twice.
 */
function legacyEmployee(string $number, ?string $plainNationalId): string
{
    $employee = Employee::query()->create([
        'employee_number' => $number, 'first_name' => 'Legacy', 'last_name' => 'Row', 'full_name' => 'Legacy Row', 'status' => EmployeeStatus::Active,
    ]);
    // Written past the model, as rows from before encryption were.
    DB::table('employees')->where('id', $employee->id)->update(['national_id' => $plainNationalId, 'national_id_hash' => null]);

    return $employee->id;
}

function legacyUser(string $email, string $plainNationalId, string $plainPhone): int
{
    $user = User::factory()->create(['email' => $email]);
    DB::table('users')->where('id', $user->id)->update(['national_id' => $plainNationalId, 'phone_number' => $plainPhone, 'national_id_hash' => null]);

    return $user->id;
}

it('changes nothing on a dry run', function (): void {
    $id = legacyEmployee('ENC-1', '1234567890123456');

    $this->artisan('sensitive-data:encrypt-existing', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::table('employees')->where('id', $id)->value('national_id'))->toBe('1234567890123456')
        ->and(DB::table('employees')->where('id', $id)->value('national_id_hash'))->toBeNull();
});

it('encrypts plaintext employee national IDs, sets their lookup hash and leaves encrypted rows untouched', function (): void {
    $plainId = legacyEmployee('ENC-2', '1234567890123456');
    $encrypted = Employee::query()->create([
        'employee_number' => 'ENC-3', 'first_name' => 'Already', 'last_name' => 'Encrypted', 'full_name' => 'Already Encrypted',
        'status' => EmployeeStatus::Active, 'national_id' => '6543210987654321',
    ]);
    $encryptedBefore = DB::table('employees')->where('id', $encrypted->id)->value('national_id');

    $this->artisan('sensitive-data:encrypt-existing')->assertExitCode(0);

    $raw = DB::table('employees')->where('id', $plainId)->first();
    expect($raw->national_id)->not->toBe('1234567890123456')                          // no plaintext at rest
        ->and(Employee::query()->find($plainId)->national_id)->toBe('1234567890123456') // still readable
        ->and($raw->national_id_hash)->toBe(hash('sha256', '1234567890123456'))       // duplicate check works
        ->and(DB::table('employees')->where('id', $encrypted->id)->value('national_id'))->toBe($encryptedBefore);
});

it('encrypts plaintext user national IDs and phones and refreshes their lookup hash', function (): void {
    $id = legacyUser('legacy@example.test', '1111222233334444', '+251911000999');

    $this->artisan('sensitive-data:encrypt-existing')->assertExitCode(0);

    $raw = DB::table('users')->where('id', $id)->first();
    $user = User::query()->find($id);
    expect($raw->national_id)->not->toBe('1111222233334444')
        ->and($raw->phone_number)->not->toBe('+251911000999')
        ->and($user->national_id)->toBe('1111222233334444')
        ->and($user->phone_number)->toBe('+251911000999')
        ->and($raw->national_id_hash)->toBe(hash('sha256', '1111222233334444'));
});

it('is safe to run twice: the second run rewrites nothing', function (): void {
    $id = legacyEmployee('ENC-4', '1234567890123456');
    $this->artisan('sensitive-data:encrypt-existing')->assertExitCode(0);
    $afterFirst = DB::table('employees')->where('id', $id)->value('national_id');

    $this->artisan('sensitive-data:encrypt-existing')->assertExitCode(0);

    expect(DB::table('employees')->where('id', $id)->value('national_id'))->toBe($afterFirst);
});
