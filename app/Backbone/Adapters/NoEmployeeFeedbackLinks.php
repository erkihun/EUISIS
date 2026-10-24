<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\EmployeeFeedbackLinks;
use App\Models\Employee;
use App\Models\User;

/** The default when no service-feedback module is installed: no links. */
final class NoEmployeeFeedbackLinks implements EmployeeFeedbackLinks
{
    public function provision(Employee $employee, ?User $actor = null): void {}

    public function publicUrlFor(Employee $employee): ?string
    {
        return null;
    }
}
