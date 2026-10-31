<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Rbac\ApplicantOwnedRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Recruitment\Domain\Models\VacancyApplication;
use Modules\Transfer\Domain\Models\TransferApplication;
use Spatie\Permission\Models\Role;

/*
 * The Super Admin role bypass never lets an applicant decide their own
 * transfer or vacancy application: the policy refuses them, Super Admin or
 * not. The Gate recognises those records through ApplicantOwnedRecord, which
 * the modules implement, so it names no module class (a moved class name in
 * the Gate once turned the rule into a silent no-op).
 */

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
    // In memory only: the rule compares the account's employee with the applicant.
    $this->admin->employee_id = (string) Str::uuid();
});

it('marks both application records as applicant-owned', function (): void {
    expect(new TransferApplication)->toBeInstanceOf(ApplicantOwnedRecord::class)
        ->and(new VacancyApplication)->toBeInstanceOf(ApplicantOwnedRecord::class);
});

it('refuses a Super Admin every decision on their own application', function (string $class, array $abilities): void {
    $own = new $class(['employee_id' => $this->admin->employee_id]);
    $other = new $class(['employee_id' => (string) Str::uuid()]);

    foreach ($abilities as $ability) {
        expect(Gate::forUser($this->admin)->allows($ability, $own))->toBeFalse("{$class}::{$ability} on their own application")
            ->and(Gate::forUser($this->admin)->allows($ability, $other))->toBeTrue("{$class}::{$ability} on someone else's application");
    }
})->with([
    'transfer' => [TransferApplication::class, ['screen', 'select', 'reject', 'approveRelease', 'approveReceiving', 'approveFinal']],
    'vacancy' => [VacancyApplication::class, ['screen', 'select', 'reject', 'initiateTransfer']],
]);
