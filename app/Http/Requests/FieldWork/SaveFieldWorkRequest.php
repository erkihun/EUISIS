<?php

declare(strict_types=1);

namespace App\Http\Requests\FieldWork;

use App\Enums\FieldWorkDestinationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFieldWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('field_work.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'field_work_type_id' => ['required', 'uuid', Rule::exists('field_work_types', 'id')->where('is_active', true)],
            'destination_type' => ['required', Rule::in(FieldWorkDestinationType::values())],
            'destination_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'], 'destination_organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'external_organization_name' => ['nullable', 'string', 'max:255'], 'external_contact_person' => ['nullable', 'string', 'max:160'], 'external_contact_phone' => ['nullable', 'string', 'max:64'],
            'destination_location' => ['nullable', 'string', 'max:255'], 'destination_address' => ['nullable', 'string', 'max:2000'],
            'destination_latitude' => ['nullable', 'numeric', 'between:-90,90'], 'destination_longitude' => ['nullable', 'numeric', 'between:-180,180'], 'destination_radius_meters' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'purpose' => ['required', 'string', 'max:4000'], 'activity_description' => ['nullable', 'string', 'max:10000'],
            'starts_at' => ['required', 'date'], 'expected_return_at' => ['required', 'date', 'after:starts_at'], 'actual_departure_at' => ['nullable', 'date'],
            'is_full_day' => ['boolean'], 'is_multi_day' => ['boolean'],
        ];
    }
}
