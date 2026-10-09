<?php

namespace App\Services\Employees;

use App\Models\Employee;

class EmployeeSelfServicePolicy
{
    public const EDITABLE = ['address', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'preferred_language', 'notification_preferences'];
    public const CORRECTIONS = ['full_name', 'date_of_birth', 'national_id', 'nationality', 'employee_number', 'employment_type', 'gender'];

    public function fields(Employee $employee, array $printed): array
    {
        $result = [];
        foreach (array_unique([...array_keys($employee->getAttributes()), ...self::EDITABLE, 'photo', 'organization', 'organization_unit', 'position', 'job_grade']) as $field) {
            $cardField = match ($field) { 'full_name', 'first_name', 'middle_name', 'last_name', 'name_en' => 'name', 'photo_path' => 'photo', default => $field };
            $impact = in_array($cardField, $printed, true);
            $access = match (true) {
                in_array($field, ['email', 'phone'], true) => 'SELF_EDITABLE_WITH_VERIFICATION',
                in_array($field, [...self::EDITABLE, 'photo', 'photo_path'], true) => $impact ? 'SELF_EDITABLE_CARD_VISIBLE' : 'SELF_EDITABLE_CARD_INDEPENDENT',
                $field === 'national_id' => 'SELF_VIEW_MASKED',
                in_array($field, [...self::CORRECTIONS, 'name_en', 'first_name', 'middle_name', 'last_name', 'status', 'organization', 'organization_unit', 'position', 'job_grade'], true) => $impact ? 'SELF_VIEW_ONLY_CARD_VISIBLE' : 'SELF_VIEW_ONLY_CARD_INDEPENDENT',
                default => 'INTERNAL_ONLY',
            };
            // Access and print impact are independent; photo is directly editable even when printed.
            if ($access !== 'INTERNAL_ONLY') {
                $result[$field] = ['access' => $access, 'card_visible' => $impact, 'editable' => in_array($field, [...self::EDITABLE, 'photo', 'photo_path', 'email', 'phone'], true), 'verification_required' => in_array($field, ['email', 'phone'], true)];
            }
        }
        return $result;
    }
}
