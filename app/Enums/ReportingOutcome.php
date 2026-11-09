<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a supervisor lookup ended the way it did (ADR 0025). Only RESOLVED names
 * a person; TOP_OF_HIERARCHY is the one legitimate "nobody"; every other case
 * is a configuration, vacancy, ambiguity or integrity problem that is shown,
 * never guessed around.
 */
enum ReportingOutcome: string
{
    /** A supervising position and a person holding it were found. */
    case Resolved = 'resolved';

    /** The organization's head with no parent organization head: legitimately nobody. */
    case TopOfHierarchy = 'top_of_hierarchy';

    /** The employee has no assignment in force on the date. */
    case NoAssignment = 'no_assignment';

    /** Several assignments cover the date and none is marked as the current one. */
    case AmbiguousAssignment = 'ambiguous_assignment';

    /** The assignment names no position, so nothing places the employee in the position graph. */
    case NoPosition = 'no_position';

    /** The employee's unit has no position marked as its head. */
    case UnitHeadNotConfigured = 'unit_head_not_configured';

    /** The organization (or the parent organization) has no head position. */
    case OrganizationHeadNotConfigured = 'organization_head_not_configured';

    /** The supervising position exists but nobody holds it on the date. */
    case HeadVacant = 'head_vacant';

    /** More than one person holds the supervising position (or several acting appointments overlap). */
    case AmbiguousOccupants = 'ambiguous_occupants';

    /** The published profile points at a position that is gone, inactive or in another organization. */
    case ExplicitTargetInvalid = 'explicit_target_invalid';

    /** The supervising position is inactive, archived or outside its effective dates. */
    case SupervisorPositionInactive = 'supervisor_position_inactive';

    /** The employee's unit (or one on the way up) is not active. */
    case UnitInactive = 'unit_inactive';

    /** The only holder of the supervising position is the employee themself. */
    case SelfReporting = 'self_reporting';

    /** Following the reporting lines returns to a position already visited. */
    case Cycle = 'cycle';

    /** The structure itself contradicts the rules: two heads for one unit or organization, two parent organizations. */
    case InvalidHierarchy = 'invalid_hierarchy';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }

    /** True for the one case where having no supervisor is correct. */
    public function isLegitimateEnd(): bool
    {
        return $this === self::TopOfHierarchy;
    }

    /** Something an administrator must fix: missing configuration, vacancy, ambiguity or an invalid hierarchy. */
    public function isProblem(): bool
    {
        return ! $this->isResolved() && ! $this->isLegitimateEnd();
    }

    public function label(): string
    {
        return (string) __('reporting-lines.outcomes.'.$this->value);
    }
}
