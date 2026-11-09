<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Contracts;

/** Which staff accounts are assigned to work at a cafeteria location. */
interface CafeteriaStaffAssignments
{
    /** Whether the user has an active assignment to any cafeteria location today. */
    public function hasActiveAssignment(int|string $userId): bool;
}
