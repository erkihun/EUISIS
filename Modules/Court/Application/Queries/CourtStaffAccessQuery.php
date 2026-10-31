<?php

declare(strict_types=1);

namespace Modules\Court\Application\Queries;

use Modules\Court\Contracts\CourtStaffAccess;
use Modules\Court\Domain\Models\CourtStaffMember;

final class CourtStaffAccessQuery implements CourtStaffAccess
{
    public function worksInCourt(int $userId): bool
    {
        return CourtStaffMember::query()->whereKey($userId)->where('status', 'active')->exists();
    }
}
