<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReceptionDeskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.configure') ?? false;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'name_en' => ['required', 'string', 'max:255'], 'name_am' => ['nullable', 'string', 'max:255'],
            'location_en' => ['nullable', 'string', 'max:1000'], 'location_am' => ['nullable', 'string', 'max:1000'],
            'operating_hours' => ['nullable', 'array'], 'operating_hours.*' => ['nullable', 'string', 'max:100'],
            'service_ids' => ['nullable', 'array'], 'service_ids.*' => ['uuid', 'exists:services,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
