<?php

declare(strict_types=1);

namespace Modules\Grievance\Domain\Enums;

/**
 * What a named member of a Grievance Team or Directorate does (ADR 0029):
 * head (Team leader / Director) — receives, distributes and leads the unit's
 * cases and signs the report and recommendation (Reg. 201/2018 Arts. 34(3), 35(1));
 * investigator — examines the application and evidence (Art. 34);
 * secretary — registers documents, keeps minutes, prepares the written decision (Arts. 32(2), 35(2)).
 */
enum GrievanceUnitResponsibility: string
{
    case Head = 'head';
    case Investigator = 'investigator';
    case Secretary = 'secretary';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
