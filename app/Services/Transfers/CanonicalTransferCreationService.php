<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\TransferStatus;
use App\Models\EmployeeTransfer;
use App\Models\TransferApplication;
use App\Models\User;
use DomainException;

/** Creates the canonical HR movement record; it never changes an assignment. */
readonly class CanonicalTransferCreationService
{
    public function create(TransferApplication $application, User $actor): EmployeeTransfer
    {
        $existing = EmployeeTransfer::query()
            ->where('transfer_application_id', $application->id)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $source = $application->currentAssignment()->lockForUpdate()->firstOrFail();
        $application->loadMissing('announcementPosition.position');
        $destination = $application->announcementPosition?->position
            ?? $application->announcement()->lockForUpdate()->firstOrFail()->position;
        if ($destination === null) {
            throw new DomainException('A selected transfer application requires a destination position.');
        }

        return EmployeeTransfer::query()->create([
            'employee_id' => $application->employee_id,
            'from_organization_id' => $application->releasing_organization_id,
            'to_organization_id' => $application->receiving_organization_id,
            'from_organization_unit_id' => $source->organization_unit_id,
            'to_organization_unit_id' => $destination->organization_unit_id,
            'from_position_id' => $source->position_id,
            'to_position_id' => $destination->id,
            'current_assignment_id' => $source->id,
            'transfer_application_id' => $application->id,
            'requested_by' => $application->selected_by ?? $actor->id,
            'approved_by' => $actor->id,
            'transfer_reason' => 'Transfer announcement selection',
            'effective_date' => ($application->effective_date ?? now())->toDateString(),
            'status' => TransferStatus::Approved,
            'submitted_at' => $application->submitted_at,
            'approved_at' => now(),
            'transfer_source' => 'transfer_announcement',
        ]);
    }
}
