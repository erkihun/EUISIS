<?php

declare(strict_types=1);

namespace App\Enums\Assessment;

/**
 * Master data a target rule may name. Position, occupation, organization
 * and unit are ids; grade level and job family are values on the position.
 */
enum TargetType: string
{
    case Position = 'position';
    case Occupation = 'occupation';
    case GradeLevel = 'grade_level';
    case JobFamily = 'job_family';
    case Organization = 'organization';
    case OrganizationUnit = 'organization_unit';
    case Everyone = 'everyone';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
