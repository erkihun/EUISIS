<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'status' => $this->status?->value ?? $this->status,
            'effective_date' => $this->effective_date?->toDateString(),
            'transfer_reason' => $this->transfer_reason,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toAtomString(),
            'submitted_at' => $this->submitted_at?->toAtomString(),
            'current_org_confirmed_at' => $this->current_org_confirmed_at?->toAtomString(),
            'receiving_org_confirmed_at' => $this->receiving_org_confirmed_at?->toAtomString(),
            'approved_at' => $this->approved_at?->toAtomString(),
            'rejected_at' => $this->rejected_at?->toAtomString(),
            'completed_at' => $this->completed_at?->toAtomString(),
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
                'full_name' => $this->employee->full_name,
                'status' => $this->employee->status?->value ?? $this->employee->status,
            ] : null,
            'from_organization' => $this->fromOrganization ? [
                'id' => $this->fromOrganization->id,
                'name_en' => $this->fromOrganization->name_en,
            ] : null,
            'to_organization' => $this->toOrganization ? [
                'id' => $this->toOrganization->id,
                'name_en' => $this->toOrganization->name_en,
            ] : null,
            'from_position' => $this->fromPosition ? [
                'id' => $this->fromPosition->id,
                'job_position_code' => $this->fromPosition->job_position_code,
                'title_en' => $this->fromPosition->title_en,
            ] : null,
            'to_position' => $this->toPosition ? [
                'id' => $this->toPosition->id,
                'job_position_code' => $this->toPosition->job_position_code,
                'title_en' => $this->toPosition->title_en,
            ] : null,
            'current_assignment' => $this->currentAssignment ? [
                'id' => $this->currentAssignment->id,
                'effective_from' => $this->currentAssignment->effective_from?->toDateString(),
                'effective_to' => $this->currentAssignment->effective_to?->toDateString(),
            ] : null,
            'requested_by' => $this->requestedBy?->only(['id', 'name', 'email']),
            'current_org_confirmed_by' => $this->currentOrganizationConfirmedBy?->only(['id', 'name', 'email']),
            'receiving_organization_confirmed_by' => $this->receivingOrganizationConfirmedBy?->only(['id', 'name', 'email']),
            'approved_by' => $this->approvedBy?->only(['id', 'name', 'email']),
            'rejected_by' => $this->rejectedBy?->only(['id', 'name', 'email']),
            'can' => [
                'view' => $user?->can('view', $this->resource) ?? false,
                'update' => $user?->can('update', $this->resource) ?? false,
                'submit' => $user?->can('submit', $this->resource) ?? false,
                'confirmCurrentOrganization' => $user?->can('confirmCurrentOrganization', $this->resource) ?? false,
                'confirmReceivingOrganization' => $user?->can('confirmReceivingOrganization', $this->resource) ?? false,
                'approve' => $user?->can('approve', $this->resource) ?? false,
                'reject' => $user?->can('reject', $this->resource) ?? false,
                'cancel' => $user?->can('cancel', $this->resource) ?? false,
                'complete' => $user?->can('complete', $this->resource) ?? false,
            ],
        ];
    }
}
