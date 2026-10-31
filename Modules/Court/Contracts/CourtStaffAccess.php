<?php

declare(strict_types=1);

namespace Modules\Court\Contracts;

/** Whether a platform account works in the court (an active court staff record), for the EUISIS menu. */
interface CourtStaffAccess
{
    public function worksInCourt(int $userId): bool;
}
