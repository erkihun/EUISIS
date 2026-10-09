<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->full_name,
            /*
             * The parts are sent alongside the composed name so the client can
             * honour `localization.employee_name_display`. Without them the
             * `first_last` option had no data to work from and silently fell
             * back to the full name on every list.
             */
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name_en' => $this->name_en,
            'phone' => $this->phone,
            'email' => $this->email,
            'photo_url' => $this->photo_path ? asset('storage/'.$this->photo_path) : null,
            'status' => $this->status?->value ?? $this->status,
            'employment_type' => $this->employment_type?->value ?? $this->employment_type,
            'duplicate_flags_count' => $this->whenCounted('employeeDuplicateFlags'),
            'current_assignment' => $this->whenLoaded('currentAssignment', fn (): ?array => $this->currentAssignment ? [
                'id' => $this->currentAssignment->id,
                'assignment_status' => $this->currentAssignment->assignment_status?->value ?? $this->currentAssignment->assignment_status,
                'effective_from' => $this->currentAssignment->effective_from?->toDateString(),
                'effective_to' => $this->currentAssignment->effective_to?->toDateString(),
                'organization' => $this->currentAssignment->organization ? [
                    'id' => $this->currentAssignment->organization->id,
                    'name_en' => $this->currentAssignment->organization->name_en,
                    'name_am' => $this->currentAssignment->organization->name_am,
                ] : null,
                'organization_unit' => $this->currentAssignment->organizationUnit ? [
                    'id' => $this->currentAssignment->organizationUnit->id,
                    'code' => $this->currentAssignment->organizationUnit->code,
                    'name_en' => $this->currentAssignment->organizationUnit->name_en,
                    'name_am' => $this->currentAssignment->organizationUnit->name_am,
                ] : null,
                'position' => $this->currentAssignment->position ? [
                    'id' => $this->currentAssignment->position->id,
                    'job_position_code' => $this->currentAssignment->position->job_position_code,
                    'title_en' => $this->currentAssignment->position->title_en,
                    'title_am' => $this->currentAssignment->position->title_am,
                ] : null,
            ] : null),
        ];
    }
}
