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

readonly class ScreenVacancyApplicationAction
{
    public function __construct(
        private VacancyApplicationService $applicationService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function screen(VacancyApplication $application, array $data, User $actor): VacancyApplication
    {
        if (! $this->applicationService->canScreen($application)) {
            throw ValidationException::withMessages([
                'status' => __('vacancies.cannotScreen'),
            ]);
        }

        $old = $application->toArray();

        $application->update([
            'status' => VacancyApplicationStatus::Screened->value,
            'screening_score' => $data['screening_score'] ?? null,
            'screening_notes' => $data['screening_notes'] ?? null,
            'screened_by' => $actor->id,
            'screened_at' => now(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::VacancyApplicationScreened,
            $actor,
            $application->fresh(),
            $application->announcement?->organization_id,
            oldValues: $old,
            newValues: $application->fresh()->toArray(),
        );

        return $application->fresh();
    }

    public function shortlist(VacancyApplication $application, User $actor): VacancyApplication
    {
        if (! $this->applicationService->canShortlist($application)) {
            throw ValidationException::withMessages([
                'status' => __('vacancies.cannotShortlist'),
            ]);
        }

        $old = $application->toArray();

        $application->update([
            'status' => VacancyApplicationStatus::Shortlisted->value,
            'shortlisted_by' => $actor->id,
            'shortlisted_at' => now(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::VacancyApplicationShortlisted,
            $actor,
            $application->fresh(),
            $application->announcement?->organization_id,
            oldValues: $old,
            newValues: $application->fresh()->toArray(),
        );

        return $application->fresh();
    }

    public function reject(VacancyApplication $application, ?string $reason, User $actor): VacancyApplication
    {
        if (! $this->applicationService->canReject($application)) {
            throw ValidationException::withMessages([
                'status' => __('vacancies.cannotReject'),
            ]);
        }

        $old = $application->toArray();

        $application->update([
            'status' => VacancyApplicationStatus::Rejected->value,
            'rejection_reason' => $reason,
            'rejected_by' => $actor->id,
            'rejected_at' => now(),
        ]);

        $this->writeAuditLogAction->execute(
            AuditEventType::VacancyApplicationRejected,
            $actor,
            $application->fresh(),
            $application->announcement?->organization_id,
            oldValues: $old,
            newValues: $application->fresh()->toArray(),
        );

        return $application->fresh();
    }
}
