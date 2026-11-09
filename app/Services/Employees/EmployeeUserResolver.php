<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The user account an employee is reached through (the same email join
 * User::employee() uses): a linked active account, else an active unlocked
 * account with the employee's email. Modules notify people through it.
 */
final class EmployeeUserResolver
{
    /**
     * userFor() for a whole batch in two queries, so a reminder run over
     * thousands of employees does not issue two lookups per person.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<string, User|null> employee id => user
     */
    public function usersFor(Collection $employees): array
    {
        $linked = User::query()->whereIn('employee_id', $employees->pluck('id')->all())->where('status', 'active')
            ->orderBy('id')->get()->unique('employee_id')->keyBy('employee_id');
        $emails = $employees->reject(fn (Employee $employee): bool => $linked->has($employee->id))
            ->pluck('email')->filter()->unique()->values()->all();
        $byEmail = $emails === [] ? collect() : User::query()
            ->whereNull('employee_id')->where('employee_link_locked', false)
            ->whereIn('email', $emails)->where('status', 'active')
            ->orderBy('id')->get()->unique('email')->keyBy('email');

        $users = [];
        foreach ($employees as $employee) {
            $users[$employee->id] = $linked->get($employee->id) ?? (filled($employee->email) ? $byEmail->get($employee->email) : null);
        }

        return $users;
    }

    public function userFor(Employee $employee): ?User
    {
        $linked = User::query()->where('employee_id', $employee->id)->where('status', 'active')->first();
        if ($linked) {
            return $linked;
        }
        if ($employee->email === null || $employee->email === '') {
            return null;
        }

        return User::query()
            ->whereNull('employee_id')->where('employee_link_locked', false)
            ->where('email', $employee->email)
            ->where('status', 'active')
            ->first();
    }
}
