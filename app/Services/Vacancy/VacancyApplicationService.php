<?php

declare(strict_types=1);

namespace App\Services\Vacancy;

use App\Enums\VacancyApplicationStatus;
use App\Models\VacancyApplication;

class VacancyApplicationService
{
    public function canWithdraw(VacancyApplication $application): bool
    {
        return in_array($application->status, [
            VacancyApplicationStatus::Submitted,
            VacancyApplicationStatus::Screened,
        ], true);
    }

    public function canScreen(VacancyApplication $application): bool
    {
        return $application->status === VacancyApplicationStatus::Submitted;
    }

    public function canShortlist(VacancyApplication $application): bool
    {
        return $application->status === VacancyApplicationStatus::Screened;
    }

    public function canSelect(VacancyApplication $application): bool
    {
        if (! in_array($application->status, [
            VacancyApplicationStatus::Screened,
            VacancyApplicationStatus::Shortlisted,
        ], true)) {
            return false;
        }

        $positionEntry = $application->positionEntry;

        return $positionEntry !== null && $positionEntry->availableSlots() > 0;
    }

    public function canReject(VacancyApplication $application): bool
    {
        return in_array($application->status, [
            VacancyApplicationStatus::Submitted,
            VacancyApplicationStatus::Screened,
            VacancyApplicationStatus::Shortlisted,
        ], true);
    }
}
