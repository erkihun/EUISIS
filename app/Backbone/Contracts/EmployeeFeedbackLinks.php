<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Models\Employee;
use App\Models\User;

/**
 * The public service-feedback link of an employee, as the backbone needs it:
 * provisioned when an employee is registered and printed on the ID card.
 *
 * The service-feedback module owns the link and implements this contract
 * (ServiceModuleServiceProvider). Without that module the backbone binds a
 * no-op, so registration and card printing never depend on it.
 */
interface EmployeeFeedbackLinks
{
    /** Make sure an employee who should have a feedback link has one. Runs inside the caller's transaction. */
    public function provision(Employee $employee, ?User $actor = null): void;

    /** The public feedback URL for the employee's ID card, or null when they have none. */
    public function publicUrlFor(Employee $employee): ?string;
}
