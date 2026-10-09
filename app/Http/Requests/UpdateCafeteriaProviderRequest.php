<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CafeteriaLocationType;
use App\Enums\CafeteriaOperationalStatus;
use App\Models\CafeteriaProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cafeteria location changes. The operating provider is fixed: a new
 * operator is a new location, so each transaction keeps the payee it had.
 * Moving a location to another network of the same provider is allowed and
 * audited; organization access follows the network it is now in.
 */
class UpdateCafeteriaProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $provider = $this->route('cafeteriaProvider');

        return $provider instanceof CafeteriaProvider
            ? ($this->user()?->can('update', $provider) ?? false)
            : false;
    }

    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'location_type' => ['required', Rule::enum(CafeteriaLocationType::class)],
            'cafeteria_service_network_id' => ['nullable', 'uuid', 'exists:cafeteria_service_networks,id'],
            'parent_cafeteria_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],
            'operational_status' => ['required', Rule::enum(CafeteriaOperationalStatus::class)],
            'opening_time' => ['nullable', 'date_format:H:i', 'required_with:closing_time'],
            'closing_time' => ['nullable', 'date_format:H:i', 'required_with:opening_time'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'location' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            // Only to adopt a legacy location that has no provider yet (checked in the action).
            'provider_id' => ['nullable', 'uuid', 'exists:providers,id'],
        ];
    }
}
