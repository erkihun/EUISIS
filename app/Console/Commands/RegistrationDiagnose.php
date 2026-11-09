<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Why can (or can't) this employee self-register?
 *
 * The registration screen gives one answer, such as "An account already
 * exists for this employee". This shows the record behind it: the
 * employee's status and contacts, and every account that blocks
 * registration with the reason it matched. Read-only.
 */
class RegistrationDiagnose extends Command
{
    protected $signature = 'registration:diagnose {employee_number : The number the employee types on the registration screen}';

    protected $description = 'Explain why an employee can or cannot self-register (read-only)';

    public function handle(): int
    {
        $number = trim((string) $this->argument('employee_number'));
        $employee = Employee::query()->where('employee_number', $number)->first();

        if ($employee === null) {
            $this->error("No employee has the number \"{$number}\". The screen says: employee not found.");

            return self::FAILURE;
        }

        $this->table(['Employee', 'Value'], [
            ['Number', $employee->employee_number],
            ['Name', (string) $employee->full_name],
            ['Status', $employee->status?->value ?? (string) $employee->status],
            ['Email on file', filled($employee->email) ? (string) $employee->email : 'none'],
            ['Phone on file', filled($employee->phone) ? 'yes' : 'none'],
        ]);

        $blocking = RegisteredUserController::blockingAccounts($employee)->get();

        if ($blocking->isNotEmpty()) {
            $this->warn('These accounts block registration ("An account already exists for this employee"):');
            $this->table(['User id', 'Email', 'Status', 'Why it matches', 'Created'], $blocking->map(fn (User $user): array => [
                $user->id,
                $user->email,
                (string) $user->status,
                $this->reason($user, $employee),
                $user->created_at?->toDateTimeString(),
            ])->all());
            $this->line('If the employee has never used such an account, an administrator can sign in for them by resetting its password, or remove the link (employee_id / employee_reference) on that user so they can register.');

            return self::FAILURE;
        }

        if ($employee->status !== EmployeeStatus::Active) {
            $this->warn('Not active: the screen says only active employees can register.');

            return self::FAILURE;
        }

        if (blank($employee->email) || blank($employee->phone)) {
            $this->warn('Missing email or phone: registration sends the code to both, so the screen asks to contact HR.');

            return self::FAILURE;
        }

        $this->info('Nothing blocks registration. If the code still does not arrive, check `php artisan mail:check` and the SMS settings.');

        return self::SUCCESS;
    }

    private function reason(User $user, Employee $employee): string
    {
        return collect([
            $user->employee_id === $employee->id ? 'linked to this employee record' : null,
            filled($employee->employee_number) && $user->employee_reference === $employee->employee_number ? 'carries this employee number' : null,
            filled($employee->email) && mb_strtolower((string) $user->email) === mb_strtolower((string) $employee->email) ? 'employee account with the same email' : null,
        ])->filter()->implode('; ');
    }
}
