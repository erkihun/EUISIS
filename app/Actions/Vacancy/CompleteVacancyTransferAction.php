<?php

declare(strict_types=1);

namespace App\Actions\Vacancy;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Entitlements\RecalculateEntitlementsAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeTransfer;
use App\Models\User;
use App\Models\VacancyApplication;
use App\Services\Vacancy\PositionCapacityService;
use App\Services\Vacancy\VacancyTransferService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

readonly class CompleteVacancyTransferAction
{
    public function __construct(
        private VacancyTransferService $vacancyTransferService,
        private PositionCapacityService $capacityService,
        private RecalculateEntitlementsAction $recalculateEntitlements,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(VacancyApplication $application, array $data, User $actor): EmployeeTransfer
    {
        $this->vacancyTransferService->assertTransferEligible($application);

        return DB::transaction(function () use ($application, $data, $actor): EmployeeTransfer {
            $positionEntry = $application->positionEntry()->lockForUpdate()->firstOrFail();
            $announcement = $application->announcement;
            $employee = $application->employee()->lockForUpdate()->firstOrFail();
            $currentAssignment = $employee->currentAssignment()->lockForUpdate()->firstOrFail();

            $effectiveDate = Carbon::parse($data['effective_date'] ?? now());

            $currentAssignment->update([
                'assignment_status' => AssignmentStatus::Closed,
                'is_current' => false,
                'effective_to' => $effectiveDate->toDateString(),
                'reason' => __('vacancies.transferReason'),
            ]);

            $this->capacityService->releaseOccupancyForAssignment(
                $currentAssignment->id,
                'vacancy_transfer',
                $effectiveDate,
            );

            $newAssignment = EmployeeAssignment::create([
                'employee_id' => $employee->id,
                'organization_id' => $positionEntry->organization_id,
                'position_id' => $positionEntry->position_id,
                'hierarchy_version_id' => $currentAssignment->hierarchy_version_id,
                'assignment_status' => AssignmentStatus::Active,
                'effective_from' => $effectiveDate->toDateString(),
                'effective_to' => null,
                'is_current' => true,
                'reason' => __('vacancies.transferReason'),
            ]);

            $employee->update(['current_assignment_id' => $newAssignment->id]);

            $establishment = $positionEntry->establishment;

            if ($establishment !== null) {
                $this->capacityService->recordOccupancy($establishment, $newAssignment, $effectiveDate);
            }

            $transfer = EmployeeTransfer::create([
                'employee_id' => $employee->id,
                'from_organization_id' => $currentAssignment->organization_id,
                'to_organization_id' => $positionEntry->organization_id,
                'from_position_id' => $currentAssignment->position_id,
                'to_position_id' => $positionEntry->position_id,
                'current_assignment_id' => $newAssignment->id,
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'transfer_reason' => __('vacancies.transferReason'),
                'effective_date' => $effectiveDate->toDateString(),
                'status' => TransferStatus::Completed,
                'submitted_at' => now(),
                'approved_at' => now(),
                'completed_at' => now(),
                'transfer_source' => 'vacancy',
                'vacancy_application_id' => $application->id,
                'vacancy_announcement_id' => $announcement?->id,
            ]);

            $this->vacancyTransferService->markApplicationTransferred($application, $transfer->id);

            $this->vacancyTransferService->closeAnnouncementIfFull($announcement, $actor->id);

            foreach ($employee->entitlements as $entitlement) {
                $this->recalculateEntitlements->execute($entitlement);
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::AssignmentChanged,
                $actor,
                $employee->fresh(),
                $positionEntry->organization_id,
                oldValues: $currentAssignment->toArray(),
                newValues: $newAssignment->toArray(),
            );

            $this->writeAuditLogAction->execute(
                AuditEventType::VacancyTransferCompleted,
                $actor,
                $transfer->fresh(),
                $positionEntry->organization_id,
                newValues: $transfer->fresh()->toArray(),
            );

            return $transfer->fresh();
        });
    }
}
