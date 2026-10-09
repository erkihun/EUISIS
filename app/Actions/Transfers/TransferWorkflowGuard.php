<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\EmployeeStatus;
use App\Enums\OrganizationStatus;
use App\Enums\TransferStatus;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Models\Organization;
use App\Models\Position;
use DomainException;
use Illuminate\Support\Carbon;

trait TransferWorkflowGuard
{
    private function ensureEmployeeCanTransfer(Employee $employee): void
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new DomainException('Only active employees can be transferred.');
        }

        if ($employee->currentAssignment === null || ! $employee->currentAssignment->is_current) {
            throw new DomainException('Employee must have a current active assignment.');
        }

        $hasActiveTransfer = $employee->transfers()
            ->whereIn('status', [
                TransferStatus::Draft,
                TransferStatus::Submitted,
                TransferStatus::CurrentOrganizationConfirmed,
                TransferStatus::ReceivingOrganizationConfirmed,
                TransferStatus::Approved,
            ])
            ->exists();

        if ($hasActiveTransfer) {
            throw new DomainException('Employee already has an active transfer.');
        }
    }

    private function ensureTransferTarget(Employee $employee, string $toOrganizationId, ?string $toPositionId, ?string $effectiveDate = null): void
    {
        $fromOrganizationId = $employee->currentAssignment?->organization_id;

        if ($fromOrganizationId !== $employee->currentAssignment?->organization_id) {
            throw new DomainException('Current assignment mismatch.');
        }

        if ($fromOrganizationId === $toOrganizationId) {
            throw new DomainException('Transfer target organization must differ from current organization.');
        }

        $organization = Organization::query()->findOrFail($toOrganizationId);

        if ($organization->status !== OrganizationStatus::Active) {
            throw new DomainException('Target organization must be active.');
        }

        if ($toPositionId !== null) {
            $position = Position::query()->findOrFail($toPositionId);

            if ($position->organization_id !== $toOrganizationId) {
                throw new DomainException('Destination position must belong to the destination organization.');
            }

            if (! $position->isSelectable($effectiveDate === null ? null : Carbon::parse($effectiveDate))) {
                throw new DomainException('Inactive or archived positions cannot be used for a new transfer.');
            }
        }
    }

    private function ensureTransferNotFinal(EmployeeTransfer $transfer): void
    {
        if ($transfer->status->isFinal()) {
            throw new DomainException('This transfer is already closed.');
        }
    }
}
