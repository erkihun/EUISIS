<?php

declare(strict_types=1);

namespace Modules\Grievance\Contracts\Data;

/**
 * How a grievance committee member reached the committee (Regulation
 * 201/2018 Art. 30): the chair is appointed by the institution head, three
 * members are elected by employees, and the HR professional joins as the
 * HR representative. Values match App\Services\Legal\StatutoryCommitteeRules.
 */
enum GrievanceCommitteeSelectionSource: string
{
    case AppointedByHead = 'appointed';
    case ElectedByEmployees = 'elected';
    case HrRepresentative = 'hr_representative';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
