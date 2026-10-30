<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateReceptionAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.appointments.manage') ?? false;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['rescheduled', 'cancelled'])], 'scheduled_at' => ['nullable', 'required_if:status,rescheduled', 'date', 'after:now']];
    }
}
