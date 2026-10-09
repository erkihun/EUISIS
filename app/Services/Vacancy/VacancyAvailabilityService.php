<?php

declare(strict_types=1);

namespace App\Services\Vacancy;

use App\Enums\VacancyAnnouncementStatus;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyApplication;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class VacancyAvailabilityService
{
    public function __construct(private readonly PositionCapacityService $capacityService) {}

    public function openAnnouncementsForPosition(string $positionId): Collection
    {
        return VacancyAnnouncement::query()
            ->whereHas('positions', fn ($q) => $q->where('position_id', $positionId))
            ->where('status', VacancyAnnouncementStatus::Published->value)
            ->where(function ($q): void {
                $now = Carbon::now();
                $q->whereNull('application_opens_at')->orWhere('application_opens_at', '<=', $now);
            })
            ->where(function ($q): void {
                $now = Carbon::now();
                $q->whereNull('application_closes_at')->orWhere('application_closes_at', '>=', $now);
            })
            ->with(['positions.organization', 'positions.position'])
            ->get();
    }

    public function openAnnouncementsForOrganization(string $organizationId): Collection
    {
        return VacancyAnnouncement::query()
            ->whereHas('positions', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('status', VacancyAnnouncementStatus::Published->value)
            ->where(function ($q): void {
                $now = Carbon::now();
                $q->whereNull('application_opens_at')->orWhere('application_opens_at', '<=', $now);
            })
            ->where(function ($q): void {
                $now = Carbon::now();
                $q->whereNull('application_closes_at')->orWhere('application_closes_at', '>=', $now);
            })
            ->with(['positions.organization', 'positions.position'])
            ->get();
    }

    public function announcementHasAvailableSlots(VacancyAnnouncement $announcement): bool
    {
        return $announcement->isAcceptingApplications();
    }

    public function employeeHasApplicationForPosition(string $positionEntryId, string $employeeId): bool
    {
        return VacancyApplication::query()
            ->where('vacancy_announcement_position_id', $positionEntryId)
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', ['withdrawn'])
            ->exists();
    }
}
