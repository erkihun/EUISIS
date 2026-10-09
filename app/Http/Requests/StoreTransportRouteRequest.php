<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('transport-routes.create') ?? false) || (auth('provider')->user()?->canUseServicePermission('provider.transport.routes.manage') ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => ['nullable', 'uuid', 'exists:providers,id'],
            'route_code' => ['required', 'string', 'max:50'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'origin_en' => ['required', 'string', 'max:255'],
            'origin_am' => ['nullable', 'string', 'max:255'],
            'destination_en' => ['required', 'string', 'max:255'],
            'destination_am' => ['nullable', 'string', 'max:255'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'assigned_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'assigned_scope_type' => ['required', Rule::in(['self', 'subtree', 'citywide'])],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
