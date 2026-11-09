<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only shape of an employee that My Portal ever sees.
 *
 * Whitelisted fields only: no ids, hashes, encrypted internals or metadata,
 * and the National ID is masked to its last four digits. Names of the
 * placement and the employment type are sent in both languages because the
 * portal's language is chosen in the browser, not on the server.
 */
class EmployeeSelfServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->resource;
        $assignment = $employee->currentAssignment;

        return [
            'full_name' => $employee->full_name, 'name_en' => $employee->name_en,
            'employee_number' => $employee->employee_number, 'status' => $employee->status?->value,
            // The version query defeats the browser's image cache after a new
            // photo is saved; the URL still carries no employee identifier.
            'photo_url' => $employee->photo_path
                ? route('employee.photo', ['v' => $employee->updated_at?->timestamp], false)
                : null,
            'email' => $employee->email, 'phone' => $employee->phone, 'address' => $employee->address,
            'emergency_contact_name' => $employee->emergency_contact_name,
            'emergency_contact_phone' => $employee->emergency_contact_phone,
            'emergency_contact_relationship' => $employee->emergency_contact_relationship,
            'preferred_language' => $employee->preferred_language,
            'notification_preferences' => ['email' => (bool) ($employee->notification_preferences['email'] ?? true)],
            'date_of_birth' => $employee->date_of_birth?->toDateString(), 'gender' => $employee->gender,
            'nationality' => $employee->nationality,
            'national_id' => $employee->national_id ? '**** **** '.mb_substr($employee->national_id, -4) : null,
            'employment_type' => $employee->employment_type?->label('en'),
            'employment_type_am' => $employee->employment_type?->label('am'),
            'organization' => $assignment?->organization?->name_en,
            'organization_am' => $assignment?->organization?->name_am,
            'organization_unit' => $assignment?->organizationUnit?->name_en,
            'organization_unit_am' => $assignment?->organizationUnit?->name_am,
            'position' => $assignment?->position?->title_en,
            'position_am' => $assignment?->position?->title_am,
            'job_grade' => $assignment?->position?->grade_level,
            'effective_from' => $assignment?->effective_from?->toDateString(),
        ];
    }
}
