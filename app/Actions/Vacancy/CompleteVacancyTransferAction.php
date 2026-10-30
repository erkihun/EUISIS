<?php

declare(strict_types=1);

namespace App\Actions\Vacancy;

use App\Actions\Audit\WriteAuditLogAction;
use App\Backbone\Commands\TransferAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\TransferStatus;
use App\Models\EmployeeTransfer;
use App\Models\Position;
use App\Models\User;
use App\Models\VacancyApplication;
use App\Services\Employees\EmploymentStatusTransition;
use App\Services\Integration\Events\TransferEvents;
use App\Services\OrganizationDesign\JobDescriptionService;
use App\Services\Positions\PositionCapacityService;
use App\Services\Vacancy\VacancyTransferService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Entitlements\Application\Actions\Entitlements\RecalculateEntitlementsAction;

readonly class CompleteVacancyTransferAction
{
    public function __construct(
        private VacancyTransferService $vacancyTransferService,
        private PositionCapacityService $capacityService,
        private RecalculateEntitlementsAction $recalculateEntitlements,
        private WriteAuditLogAction $writeAuditLogAction,
        private JobDescriptionService $jobDescriptions,
        private TransferEvents $transferEvents,
        private TransferAssignment $transferAssignment,
    ) {}

    public function execute(VacancyApplication $application, array $data, User $actor): EmployeeTransfer
    {
        // Fast rejection before taking locks; re-checked under the locks below.
        $this->vacancyTransferService->assertTransferEligible($application);

        return DB::transaction(function () use ($application, $data, $actor): EmployeeTransfer {
            // Lock order: vacancy entry → position → application → employee →
            // assignment. The entry lock serializes every application to the
            // same vacancy, so the slot count below cannot be raced; the
            // position lock serializes with registration and other transfers.
            $positionEntry = $application->positionEntry()->lockForUpdate()->firstOrFail();
            $destinationPosition = Position::query()->whereKey($positionEntry->position_id)->lockForUpdate()->first();
            $application = VacancyApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $this->vacancyTransferService->assertTransferEligible($application);

            $announcement = $application->announcement;
            $employee = $application->employee()->lockForUpdate()->firstOrFail();
            $currentAssignment = $employee->currentAssignment()->lockForUpdate()->firstOrFail();

            $effectiveDate = Carbon::parse($data['effective_date'] ?? now());

            // The position as a whole, under its lock (CON-5): people placed
            // by registration hold slots without an occupancy row.
            if (! $this->capacityService->hasFreeSlot($positionEntry->position_id, $effectiveDate)) {
                throw ValidationException::withMessages(['slots' => __('vacancies.noSlotsRemaining')]);
            }

            // The destination must be a selectable position of the vacancy's organization,
            // and the employee must hold an active placement: an ended one means the
            // employment ended (ADR 0002) and is resumed, not transferred.
            if ($destinationPosition === null
                || ! $destinationPosition->isSelectable($effectiveDate)
                || (string) $destinationPosition->organization_id !== (string) $positionEntry->organization_id) {
                throw ValidationException::withMessages(['position' => __('vacancies.destinationNotSelectable')]);
            }

            if ($currentAssignment->assignment_status !== AssignmentStatus::Active
                || (string) $employee->current_assignment_id !== (string) $currentAssignment->id
                || in_array($employee->status, EmploymentStatusTransition::SEPARATED, true)) {
                throw ValidationException::withMessages(['employee' => __('vacancies.employeeNotPlaced')]);
            }

            $sourceSnapshot = $currentAssignment->toArray();

            // The old placement ends the day before the new one starts, so the
            // two intervals never overlap; the backbone command does the move.
            $newAssignment = $this->transferAssignment->execute(
                $employee,
                $currentAssignment,
                [
                    'organization_id' => $positionEntry->organization_id,
                    // The destination unit and its job description in force, so the
                    // placement is complete in itself (master backbone audit).
                    'organization_unit_id' => $destinationPosition?->organization_unit_id,
                    'position_id' => $positionEntry->position_id,
                    'hierarchy_version_id' => $currentAssignment->hierarchy_version_id,
                    'job_description_id' => $destinationPosition === null ? null : $this->jobDescriptions->inForceFor($destinationPosition, $effectiveDate->toDateString())?->getKey(),
                ],
                $effectiveDate,
                closeReason: __('vacancies.transferReason'),
                openReason: __('vacancies.transferReason'),
            );

            // Released the day the old assignment ends (as CompleteTransferAction),
            // so the employee never holds both positions on the effective date.
            $this->capacityService->releaseOccupancyForAssignment(
                $currentAssignment->id,
                'vacancy_transfer',
                $effectiveDate->copy()->subDay(),
            );

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
                'from_organization_unit_id' => $sourceSnapshot['organization_unit_id'] ?? null,
                'to_organization_unit_id' => $newAssignment->organization_unit_id,
                'current_assignment_id' => $newAssignment->id,
                // Both placements as they were, so the record keeps its history (as CompleteTransferAction).
                'destination_assignment_id' => $newAssignment->id,
                'source_assignment_snapshot' => $sourceSnapshot,
                'destination_assignment_snapshot' => $newAssignment->toArray(),
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

            $this->transferEvents->record($employee, $currentAssignment, $newAssignment, (string) $transfer->id, 'vacancy', $effectiveDate);

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
