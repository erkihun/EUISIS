<?php

declare(strict_types=1);

namespace App\Enums\Grievance;

/**
 * Role of a hearing participant.
 */
enum GrievanceParticipantRole: string
{
    case Complainant = 'complainant';
    case CommitteeMember = 'committee_member';
    case Respondent = 'respondent';
    case Witness = 'witness';
    case HrRepresentative = 'hr_representative';
    case LegalRepresentative = 'legal_representative';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
