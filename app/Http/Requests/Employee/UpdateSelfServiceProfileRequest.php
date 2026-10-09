<?php

namespace App\Http\Requests\Employee;

use App\Services\Employees\EmployeeSelfServicePolicy;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSelfServiceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        return [
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:100'],
            'preferred_language' => ['sometimes', 'in:en,am'],
            'notification_preferences' => ['sometimes', 'array:email'],
            'notification_preferences.email' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (array_diff(array_keys($this->except('_token', '_method')), [...EmployeeSelfServicePolicy::EDITABLE, 'photo'])) {
                $validator->errors()->add('profile', __('employee-portal.forbidden_field'));
            }
        }];
    }
}
