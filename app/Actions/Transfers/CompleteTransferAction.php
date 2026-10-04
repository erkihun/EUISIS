<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Entitlements\RecalculateEntitlementsAction;
use App\Enums\AssignmentStatus;
use App\Enums\AuditEventType;
use App\Enums\TransferApplicationStatus;
use App\Enums\TransferCardReprintPolicy;
use App\Enums\TransferServiceRecalculationPolicy;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\Performance\EmployeeAgreementService;
use DomainException;
use Illuminate\Support\Facades\DB;

readonly class CompleteTransferAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLogAction,
        private RecalculateEntitlementsAction $recalculateEntitlementsAction,
        private EmployeeAgreementService $employeeAgreements,
    ) {}

    public function execute(TransferApplication $application, User $actor): TransferApplication
    {
        if ($application->status !== TransferApplicationStatus::Approved) {
            throw new DomainException('Transfer application must be fully approved before completion.');
        }

        $settings = TransferSetting::current();

        return DB::transaction(function () use ($application, $actor, $settings): TransferApplication {
            // Lock employee to prevent concurrent modifications
            $employee = $application->employee()->lockForUpdate()->firstOrFail();
            $currentAssignment = $employee->currentAssignment()->lockForUpdate()->firstOrFail();

            // Employee identity must not change
            if ($employee->id !== $application->employee_id) {
                throw new DomainException('Employee identity mismatch. Aborting transfer.');
            }

            // Close the current assignment
            $currentAssignment->update([
                'assignment_status' => AssignmentStatus::Closed,
                'is_current' => false,
                'effective_to' => now()->toDateString(),
                'reason' => 'Transfer to '.($application->receivingOrganization?->name_en ?? 'receiving organization'),
            ]);

            // Create the new assignment — only assignment data changes, NOT employee identity
            $newAssignment = EmployeeAssignment::query()->create([
                'employee_id' => $employee->id,        // same employee UUID
                'organization_id' => $application->receiving_organization_id,
                'organization_unit_id' => $application->announcement?->position?->organization_unit_id ?? null,
                'position_id' => $application->announcement?->position_id,
                'hierarchy_version_id' => $currentAssignment->hierarchy_version_id,
                'assignment_status' => AssignmentStatus::Active,
                'effective_from' => now()->toDateString(),
                'effective_to' => null,
                'is_current' => true,
                'reason' => 'Transfer from '.($application->releasingOrganization?->name_en ?? 'releasing organization'),
            ]);

            // Update employee's current assignment pointer — employee UUID/number unchanged
            $employee->update(['current_assignment_id' => $newAssignment->id]);
            $this->employeeAgreements->closeForAssignmentChange($currentAssignment, $newAssignment, $actor);

            // Mark application as transferred
            $application->update(['status' => TransferApplicationStatus::Transferred->value]);

            // Handle card reprint per settings
            $this->handleCardReprint($employee, $newAssignment, $settings, $actor);

            // Recalculate entitlements per settings
            $this->handleEntitlementRecalculation($employee, $settings);

            // Immutable audit — assignment change
            $this->writeAuditLogAction->execute(
                AuditEventType::AssignmentChanged,
                $actor,
                $employee->fresh(),
                $application->receiving_organization_id,
                oldValues: $currentAssignment->toArray(),
                newValues: $newAssignment->toArray(),
                reason: 'Transfer Module completion',
            );

            // Immutable audit — transfer completed
            $this->writeAuditLogAction->execute(
                AuditEventType::TransferModuleCompleted,
                $actor,
                $application->fresh(),
                $application->receiving_organization_id,
                newValues: $application->fresh()->toArray(),
            );

            return $application->fresh([
                'employee.currentAssignment',
                'releasingOrganization',
                'receivingOrganization',
                'announcement.position',
            ]);
        });
    }

    private function handleCardReprint(
        Employee $employee,
        EmployeeAssignment $newAssignment,
        TransferSetting $settings,
        User $actor,
    ): void {
        if ($settings->card_reprint_policy === TransferCardReprintPolicy::NoReprint) {
            return;
        }

        // Create a card reprint request regardless of auto/manual
        CardRequest::query()->create([
            'employee_id' => $employee->id,
            'assignment_id' => $newAssignment->id,
            'requested_by' => $actor->id,
            'reason' => 'transfer',
            'status' => $settings->card_reprint_policy === TransferCardReprintPolicy::AutoReprint
                ? 'approved'
                : 'pending',
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
