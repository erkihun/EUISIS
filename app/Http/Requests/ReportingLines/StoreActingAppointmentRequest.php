<?php

declare(strict_types=1);

namespace App\Http\Requests\ReportingLines;

use App\Models\ActingAppointment;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;

class StoreActingAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organizationId = Position::query()->whereKey($this->input('position_id'))->value('organization_id');

        return $this->user()?->can('create', [ActingAppointment::class, $organizationId === null ? null : (string) $organizationId]) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'position_id' => ['required', 'uuid', 'exists:positions,id'],
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
