<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Entitlements\RecalculateEntitlementsAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\EstablishmentStatus;
use App\Enums\OccupancyStatus;
use App\Enums\TransferApplicationStatus;
use App\Enums\TransferCardReprintPolicy;
use App\Enums\TransferServiceRecalculationPolicy;
use App\Enums\TransferStatus;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeTransfer;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionOccupancy;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\IdCards\IdCardSnapshotComparisonService;
use App\Services\Performance\EmployeeAgreementService;
use App\Services\Vacancy\PositionCapacityService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Canonical, assignment-only implementation for an approved transfer application. */
readonly class CompleteTransferAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private RecalculateEntitlementsAction $recalculateEntitlementsAction,
        private EmployeeAgreementService $employeeAgreements,
        private PositionCapacityService $capacityService,
        private IdCardSnapshotComparisonService $idCardSnapshotComparison,
    ) {}

    public function execute(TransferApplication $application, User $actor): TransferApplication
    {
        try {
            return DB::transaction(function () use ($application, $actor): TransferApplication {
                $application = TransferApplication::query()->lockForUpdate()->findOrFail($application->id);

                // Retried requests are safe after the original transaction commits.
                if ($application->status === TransferApplicationStatus::Transferred) {
                    return $application->fresh();
                }
                if (! in_array($application->status, [
                    TransferApplicationStatus::Approved,
                    TransferApplicationStatus::ImplementationFailed,
                ], true)) {
                    throw new DomainException('Transfer application must be fully approved before implementation.');
                }

                $effectiveDate = ($application->effective_date ?? now())->startOfDay();
                if ($effectiveDate->isFuture()) {
                    throw new DomainException('Transfer implementation cannot occur before its effective date.');
                }

                $employee = $application->employee()->lockForUpdate()->firstOrFail();
                $currentAssignment = EmployeeAssignment::query()->lockForUpdate()->findOrFail($application->current_assignment_id);
                $this->assertImplementationState($application, $employee, $currentAssignment, $effectiveDate);

                $announcement = TransferAnnouncement::query()->lockForUpdate()->findOrFail($application->announcement_id);
                $application->loadMissing('announcementPosition');
                $position = Position::query()->lockForUpdate()->findOrFail(
                    $application->announcementPosition?->position_id ?? $announcement->position_id,
                );
                $establishment = $this->lockAvailableEstablishment($position, $application, $effectiveDate);

                $sourceSnapshot = $currentAssignment->toArray();
                $currentAssignment->update([
                    'assignment_status' => AssignmentStatus::Closed,
                    'is_current' => false,
                    // Intervals are inclusive: the successor starts on its effective date.
                    'effective_to' => $effectiveDate->copy()->subDay()->toDateString(),
                    'reason' => 'Transfer to '.($application->receivingOrganization?->name_en ?? 'receiving organization'),
                ]);
                $this->capacityService->releaseOccupancyForAssignment($currentAssignment->id, 'transfer', $effectiveDate->copy()->subDay());

                // The employee UUID and employee number are never written here.
                $newAssignment = EmployeeAssignment::query()->create([
                    'employee_id' => $employee->id,
                    'organization_id' => $application->receiving_organization_id,
                    'organization_unit_id' => $position->organization_unit_id,
                    'position_id' => $position->id,
                    'hierarchy_version_id' => $currentAssignment->hierarchy_version_id,
                    'assignment_status' => AssignmentStatus::Active,
                    'effective_from' => $effectiveDate->toDateString(),
                    'effective_to' => null,
                    'is_current' => true,
                    'reason' => 'Transfer from '.($application->releasingOrganization?->name_en ?? 'releasing organization'),
                ]);

                $employee->update(['current_assignment_id' => $newAssignment->id]);
                $this->capacityService->recordOccupancy($establishment, $newAssignment, $effectiveDate);
                $this->employeeAgreements->closeForAssignmentChange($currentAssignment, $newAssignment, $actor);

                // A final selection approval normally created this canonical transfer.
                // Keep the fallback for legacy approved applications during rollout.
                $transfer = EmployeeTransfer::query()->firstOrNew([
                    'transfer_application_id' => $application->id,
                ]);
                $transfer->fill([
                    'employee_id' => $employee->id,
                    'from_organization_id' => $currentAssignment->organization_id,
                    'to_organization_id' => $newAssignment->organization_id,
                    'from_organization_unit_id' => $currentAssignment->organization_unit_id,
                    'to_organization_unit_id' => $newAssignment->organization_unit_id,
                    'from_position_id' => $currentAssignment->position_id,
                    'to_position_id' => $newAssignment->position_id,
                    'current_assignment_id' => $currentAssignment->id,
                    'destination_assignment_id' => $newAssignment->id,
                    'transfer_application_id' => $application->id,
                    'requested_by' => $application->selected_by ?? $actor->id,
                    'approved_by' => $actor->id,
                    'transfer_reason' => 'Transfer announcement application',
                    'effective_date' => $effectiveDate->toDateString(),
                    'status' => TransferStatus::Completed,
                    'submitted_at' => $application->submitted_at,
                    'approved_at' => $application->approved_at ?? now(),
                    'completed_at' => now(),
                    'transfer_source' => 'announcement',
                    'source_assignment_snapshot' => $sourceSnapshot,
                    'destination_assignment_snapshot' => $newAssignment->toArray(),
                ]);
                $transfer->save();

                $application->update([
                    'status' => TransferApplicationStatus::Transferred->value,
                    'implementation_assignment_id' => $newAssignment->id,
                    'implemented_by' => $actor->id,
                    'implemented_at' => now(),
                    'implementation_failed_at' => null,
                    'implementation_failure' => null,
                ]);

                $settings = TransferSetting::current();
                $this->handleCardReprint($employee, $newAssignment, $settings, $actor);
                $this->idCardSnapshotComparison->evaluate($employee->fresh());
                $this->handleEntitlementRecalculation($employee, $settings);

                $this->writeAuditLogAction->execute(
                    AuditEventType::AssignmentChanged, $actor, $employee->fresh(), $newAssignment->organization_id,
                    oldValues: $sourceSnapshot, newValues: $newAssignment->toArray(), reason: 'Transfer Module implementation',
                );
                $this->writeAuditLogAction->execute(
                    AuditEventType::TransferModuleCompleted, $actor, $transfer, $newAssignment->organization_id,
                    newValues: ['transfer_id' => $transfer->id, 'application_id' => $application->id],
                );

                return $application->fresh([
                    'employee.currentAssignment', 'releasingOrganization', 'receivingOrganization',
                    'announcement.position', 'implementationAssignment',
                ]);
            });
        } catch (DomainException $exception) {
            // A premature click is a scheduling guard, not an implementation failure.
            if ($exception->getMessage() !== 'Transfer implementation cannot occur before its effective date.') {
                $this->recordImplementationFailure($application, $exception);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            $this->recordImplementationFailure($application, $exception);
            throw $exception;
        }
    }

    private function recordImplementationFailure(TransferApplication $application, \Throwable $exception): void
    {
        // Preserve a non-sensitive failure reason outside the rolled-back transaction.
        TransferApplication::query()->whereKey($application->id)
            ->whereIn('status', [TransferApplicationStatus::Approved->value, TransferApplicationStatus::ImplementationFailed->value])
            ->update([
                'status' => TransferApplicationStatus::ImplementationFailed->value,
                'implementation_failed_at' => now(),
                'implementation_failure' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
    }

    private function assertImplementationState(TransferApplication $application, Employee $employee, EmployeeAssignment $assignment, Carbon $effectiveDate): void
    {
        if ($employee->id !== $application->employee_id) {
            throw new DomainException('Employee identity mismatch. Aborting transfer.');
        }
        if ($employee->current_assignment_id !== $assignment->id || ! $assignment->is_current) {
            throw new DomainException('The employee assignment changed after approval; review the transfer before retrying.');
        }
        if ($assignment->assignment_status !== AssignmentStatus::Active) {
            throw new DomainException('Only an active assignment can be transferred.');
        }
        if ($assignment->effective_from !== null && $assignment->effective_from->gte($effectiveDate)) {
            throw new DomainException('The effective date would create an empty or overlapping source assignment.');
        }
    }

    private function lockAvailableEstablishment(Position $position, TransferApplication $application, Carbon $effectiveDate): PositionEstablishment
    {
        if ($position->organization_id !== $application->receiving_organization_id || ! $position->isSelectable($effectiveDate)) {
            throw new DomainException('The approved destination position is no longer valid for this transfer.');
        }

        $establishment = PositionEstablishment::query()
            ->where('organization_id', $application->receiving_organization_id)
            ->where('position_id', $position->id)
            ->where('status', EstablishmentStatus::Approved->value)
            ->whereDate('effective_from', '<=', $effectiveDate->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $effectiveDate->toDateString()))
            ->lockForUpdate()->first();
        if ($establishment === null) {
            throw new DomainException('No approved position establishment is available for the transfer destination.');
        }

        $occupied = PositionOccupancy::query()->where('position_establishment_id', $establishment->id)
            ->where('status', OccupancyStatus::Active->value)
            ->whereDate('occupied_from', '<=', $effectiveDate->toDateString())
            ->lockForUpdate()->get()->count();
        if ($occupied >= $establishment->approved_slots) {
            throw new DomainException('The transfer destination has no available approved capacity.');
        }

        return $establishment;
    }

    private function handleCardReprint(Employee $employee, EmployeeAssignment $assignment, TransferSetting $settings, User $actor): void
    {
        if ($settings->card_reprint_policy === TransferCardReprintPolicy::NoReprint) {
            return;
        }
        CardRequest::query()->create([
            'employee_id' => $employee->id, 'assignment_id' => $assignment->id, 'requested_by' => $actor->id,
            'reason' => 'transfer',
            'status' => $settings->card_reprint_policy === TransferCardReprintPolicy::AutoReprint ? 'approved' : 'pending',
        ]);
    }

    private function handleEntitlementRecalculation(Employee $employee, TransferSetting $settings): void
    {
        if ($settings->service_recalculation_policy === TransferServiceRecalculationPolicy::NoRecalculation) {
            return;
        }
        foreach ($employee->entitlements as $entitlement) {
            $this->recalculateEntitlementsAction->execute($entitlement);
        }
    }
}
