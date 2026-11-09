<?php

declare(strict_types=1);

namespace Modules\Recruitment\Application\Services;

use Illuminate\Validation\ValidationException;
use Modules\Recruitment\Contracts\Data\VacancyAnnouncementStatus;
use Modules\Recruitment\Contracts\Data\VacancyApplicationStatus;
use Modules\Recruitment\Domain\Models\VacancyAnnouncement;
use Modules\Recruitment\Domain\Models\VacancyApplication;

class VacancyTransferService
{
    public function assertTransferEligible(VacancyApplication $application): void
    {
        if ($application->status !== VacancyApplicationStatus::Selected) {
            throw ValidationException::withMessages([
                'application' => __('vacancies.applicationNotSelected'),
            ]);
        }

        $announcement = $application->announcement;

        if ($announcement === null || $announcement->status === VacancyAnnouncementStatus::Cancelled) {
            throw ValidationException::withMessages([
                'announcement' => __('vacancies.announcementCancelled'),
            ]);
        }

        $positionEntry = $application->positionEntry;

        if ($positionEntry === null) {
            throw ValidationException::withMessages([
                'position' => __('vacancies.noSlotsRemaining'),
            ]);
        }

        $alreadyTransferred = $positionEntry->applications()
            ->where('status', VacancyApplicationStatus::Transferred->value)
            ->count();

        if ($alreadyTransferred >= $positionEntry->vacancy_slots) {
            throw ValidationException::withMessages([
                'slots' => __('vacancies.noSlotsRemaining'),
            ]);
        }
    }

    public function markApplicationTransferred(VacancyApplication $application, string $transferId): void
    {
        $application->update([
            'status' => VacancyApplicationStatus::Transferred->value,
            'transfer_id' => $transferId,
        ]);
    }

    public function closeAnnouncementIfFull(VacancyAnnouncement $announcement, int $closedBy): void
    {
        if (! $announcement->status->isFinal() && $announcement->allPositionsFull()) {
            $announcement->update([
                'status' => VacancyAnnouncementStatus::Closed->value,
                'closed_by' => $closedBy,
                'closed_at' => now(),
            ]);
        }
    }
}
