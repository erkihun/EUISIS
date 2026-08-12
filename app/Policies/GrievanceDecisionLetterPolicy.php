<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GrievanceDecisionLetter;
use App\Models\User;

class GrievanceDecisionLetterPolicy
{
    public function view(User $user, GrievanceDecisionLetter $letter): bool
    {
        $grievance = $letter->grievance;

        if ($grievance && $grievance->isOwnedBy($user)) {
            return true;
        }

        return $user->hasAnyPermission(['grievances.manage', 'grievances.manager', 'grievances.tribunal']);
    }

    public function download(User $user, GrievanceDecisionLetter $letter): bool
    {
        return $this->view($user, $letter);
    }
}
