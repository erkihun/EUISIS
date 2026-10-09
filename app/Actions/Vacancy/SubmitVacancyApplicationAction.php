<?php

declare(strict_types=1);

namespace App\Actions\Vacancy;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\VacancyApplicationStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyAnnouncementPosition;
use App\Models\VacancyApplication;
use App\Services\Vacancy\VacancyAvailabilityService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

readonly class SubmitVacancyApplicationAction
{
    public function __construct(
        private VacancyAvailabilityService $availabilityService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(
        VacancyAnnouncement $announcement,
        VacancyAnnouncementPosition $positionEntry,
        Employee $employee,
        User $actor,
    ): VacancyApplication {
        if (! $announcement->isAcceptingApplications()) {
            throw ValidationException::withMessages([
                'announcement' => __('vacancies.notAcceptingApplications'),
            ]);
        }

        if ($positionEntry->availableSlots() <= 0) {
            throw ValidationException::withMessages([
                'slots' => __('vacancies.noSlotsRemaining'),
            ]);
        }

        if ($this->availabilityService->employeeHasApplicationForPosition($positionEntry->id, $employee->id)) {
            throw ValidationException::withMessages([
                'employee' => __('vacancies.alreadyApplied'),
            ]);
        }

        $currentAssignment = $employee->currentAssignment;

        $application = VacancyApplication::create([
            'application_number' => $this->generateNumber(),
            'vacancy_announcement_id' => $announcement->id,
            'vacancy_announcement_position_id' => $positionEntry->id,
            'employee_id' => $employee->id,
            'current_organization_id' => $currentAssignment?->organization_id ?? $employee->organization_id,
            'current_position_id' => $currentAssignment?->position_id,
            'status' => VacancyApplicationStatus::Submitted->value,
            'applied_at' => now(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::VacancyApplicationSubmitted,
            $actor,
            $application,
            $positionEntry->organization_id,
            newValues: $application->toArray(),
        );

        return $application;
    }

    private function generateNumber(): string
    {
        return 'APP-'.now()->format('Ym').'-'.strtoupper(Str::random(6));
    }
}
