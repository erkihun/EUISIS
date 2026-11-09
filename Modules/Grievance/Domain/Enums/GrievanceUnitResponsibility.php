<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * What a named member of a Grievance Team or the Grievance Directorate does
 * (ADR 0029, owner decision 2026-11-07): a team has a team leader and
 * experts, the directorate a director and experts; there is no secretary.
 * head (Team leader / Director) — receives, distributes and leads the unit's
 * cases and signs the report and recommendation (Reg. 201/2018 Arts. 34(3), 35(1));
 * expert — examines the application and evidence and drafts the findings (Art. 34).
 */
enum GrievanceUnitResponsibility: string
{
    case Head = 'head';
    case Expert = 'expert';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
