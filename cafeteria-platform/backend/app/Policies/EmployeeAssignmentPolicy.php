<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationScope;

/** Placements (ADR 0009): seen and changed within the placement's organization. */
final class EmployeeAssignmentPolicy
{
    use ChecksOrganizationScope;

    public function view(User $user, EmployeeAssignment $assignment): bool
    {
        return $this->permits($user, 'assignments.view', (string) $assignment->organization_id);
    }

    /** Moving someone needs the permission in the organization they leave and, for the target, in the one they join. */
    public function change(User $user, EmployeeAssignment $assignment, ?string $targetOrganizationId = null): bool
    {
        return $this->permits($user, 'assignments.change', (string) $assignment->organization_id, $assignment->is_current)
            && ($targetOrganizationId === null || $this->permits($user, 'assignments.change', $targetOrganizationId));
    }
}
