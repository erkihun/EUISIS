<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which rule put one position above another (ADR 0025), in order of precedence.
 */
enum ReportingBasis: string
{
    /** The position profile in force says "reports to" this position. */
    case ExplicitProfile = 'explicit_profile';

    /** The position marked as head of the employee's own unit. */
    case UnitHead = 'unit_head';

    /** A unit head reports to the head of the parent unit. */
    case ParentUnitHead = 'parent_unit_head';

    /** A top-level unit head, or organization-level staff, reports to the organization's head. */
    case OrganizationHead = 'organization_head';

    /** The organization's head reports to the head of the parent organization in the published hierarchy. */
    case ParentOrganizationHead = 'parent_organization_head';

    public function label(): string
    {
        return (string) __('reporting-lines.bases.'.$this->value);
    }
}
