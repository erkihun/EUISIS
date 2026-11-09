<?php

declare(strict_types=1);

namespace Modules\Grievance\Application\Services;

use Modules\Grievance\Contracts\Data\CommitteeType;

/**
 * Which permission governs a committee (ADR 0031). Disciplinary committees
 * belong to Discipline Management and use its permissions; every other
 * committee type keeps the grievance committee permissions.
 */
final class CommitteePermissions
{
    /** @return array{view: string, manage: string, members: string, approve: string} */
    public static function for(CommitteeType|string|null $type): array
    {
        $type = $type instanceof CommitteeType ? $type : CommitteeType::tryFrom((string) $type);

        return $type === CommitteeType::Disciplinary
            // Regulation 201/2018 Art. 5: the head of the institution sets up the committee.
            ? ['view' => 'discipline.view', 'manage' => 'discipline.manage', 'members' => 'discipline.manage', 'approve' => 'discipline.decide']
            : ['view' => 'grievance_committees.view', 'manage' => 'grievance_committees.manage', 'members' => 'grievance_committee_members.manage', 'approve' => 'grievance_committees.approve'];
    }
}
