<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterReceptionVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.operate') ?? false;
    }

    public function rules(): array
    {
        return [
            'visitor_name' => ['nullable', 'required_without:phone', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'],
            'visitor_category' => ['required', Rule::in(['service_seeker', 'representative', 'employee', 'courier', 'official', 'anonymous'])],
            'employee_id' => ['nullable', 'uuid', 'exists:employees,id'], 'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'], 'service_id' => ['nullable', 'uuid', 'exists:services,id'],
            'reception_desk_id' => ['required', 'uuid', 'exists:reception_desks,id'], 'appointment_id' => ['nullable', 'uuid', 'exists:reception_appointments,id'],
            'purpose' => ['nullable', 'string', 'max:500'],
        ];
    }
}
