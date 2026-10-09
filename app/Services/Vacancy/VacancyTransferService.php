<?php

declare(strict_types=1);

namespace App\Services\Vacancy;

use App\Enums\VacancyAnnouncementStatus;
use App\Enums\VacancyApplicationStatus;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyApplication;
use Illuminate\Validation\ValidationException;

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
