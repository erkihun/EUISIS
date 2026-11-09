<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Services;

use App\Backbone\Contracts\AssignmentContextReader;

/**
 * The cafeteria module's anti-corruption adapter onto the Core: what the
 * cafeteria needs to know about an employee, read through the backbone
 * contracts instead of the Employee model. Today that is the organization an
 * employee belongs to (their current placement), which cafeteria records use
 * as their audit and scope context.
 */
final readonly class CafeteriaEmployeeContext
{
    public function __construct(private AssignmentContextReader $assignments) {}

    /** The organization of the employee's current placement, or null when they have none. */
    public function organizationOf(?string $employeeUuid): ?string
    {
        return $employeeUuid === null ? null : $this->assignments->currentFor($employeeUuid)?->organizationUuid;
    }
}
