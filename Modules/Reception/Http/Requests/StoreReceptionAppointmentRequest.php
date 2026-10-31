<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReceptionAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.appointments.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'visitor_id' => ['required', 'uuid', 'exists:reception_visitors,id'], 'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'service_id' => ['nullable', 'uuid', 'exists:services,id'], 'reception_desk_id' => ['required', 'uuid', 'exists:reception_desks,id'],
            'scheduled_at' => ['required', 'date', 'after:now'], 'status' => ['sometimes', 'in:scheduled,rescheduled'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
