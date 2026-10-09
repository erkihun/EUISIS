<?php

declare(strict_types=1);

namespace App\Actions\Vacancy;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\VacancyApplicationStatus;
use App\Models\User;
use App\Models\VacancyApplication;
use App\Services\Vacancy\VacancyApplicationService;
use Illuminate\Validation\ValidationException;

readonly class SelectVacancyApplicationAction
{
    public function __construct(
        private VacancyApplicationService $applicationService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(VacancyApplication $application, User $actor): VacancyApplication
    {
        if (! $this->applicationService->canSelect($application)) {
            throw ValidationException::withMessages([
                'status' => __('vacancies.cannotSelect'),
            ]);
        }

        $positionEntry = $application->positionEntry;

        if ($positionEntry === null || $positionEntry->availableSlots() <= 0) {
            throw ValidationException::withMessages([
                'slots' => __('vacancies.noSlotsRemaining'),
            ]);
        }

        $old = $application->toArray();

        $application->update([
            'status' => VacancyApplicationStatus::Selected->value,
            'selected_by' => $actor->id,
            'selected_at' => now(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::VacancyApplicationSelected,
            $actor,
            $application->fresh(),
            $positionEntry->organization_id,
            oldValues: $old,
            newValues: $application->fresh()->toArray(),
        );

        return $application->fresh();
    }
}
