<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CafeteriaLocationType;
use App\Enums\CafeteriaOperationalStatus;
use App\Models\CafeteriaProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new cafeteria LOCATION: which provider operates it (existing, or new),
 * which network it belongs to (a main cafeteria may start a new network),
 * and its operational settings. Who may eat here and at what subsidy is
 * configured separately (organization access, assignments, policies).
 */
class StoreCafeteriaProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CafeteriaProvider::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'provider_mode' => $this->input('provider_mode', 'existing'),
            'network_mode' => $this->input('network_mode', 'existing'),
        ]);
    }

    public function rules(): array
    {
        return [
            'provider_mode' => ['required', Rule::in(['existing', 'new'])],
            'provider_id' => ['required_if:provider_mode,existing', 'nullable', 'uuid', 'exists:providers,id'],
            'provider_code' => ['required_if:provider_mode,new', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('providers', 'provider_code')],
            'provider_name_en' => ['required_if:provider_mode,new', 'nullable', 'string', 'max:255'],
            'provider_name_am' => ['nullable', 'string', 'max:255'],

            'location_type' => ['required', Rule::enum(CafeteriaLocationType::class)],
            'network_mode' => ['required', Rule::in(['existing', 'new'])],
            'cafeteria_service_network_id' => ['required_if:network_mode,existing', 'nullable', 'uuid', 'exists:cafeteria_service_networks,id'],
            'network_code' => ['required_if:network_mode,new', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('cafeteria_service_networks', 'code')],
            'network_name_en' => ['required_if:network_mode,new', 'nullable', 'string', 'max:255'],
            'network_name_am' => ['nullable', 'string', 'max:255'],
            'parent_cafeteria_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],

            'code' => ['required', 'string', 'max:20', 'unique:cafeteria_providers,code', 'regex:/^[A-Z0-9_-]+$/'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            // Display only: the organization it primarily serves. Never access, never subsidy.
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],

            'operational_status' => ['required', Rule::enum(CafeteriaOperationalStatus::class)],
            'opening_time' => ['nullable', 'date_format:H:i', 'required_with:closing_time'],
            'closing_time' => ['nullable', 'date_format:H:i', 'required_with:opening_time'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],

            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'location' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Only a main cafeteria starts a network; branches join one, of their provider.
                if ($this->input('network_mode') === 'new' && $this->input('location_type') !== CafeteriaLocationType::Main->value) {
                    $validator->errors()->add('network_mode', __('cafeteria-policy.validation.branch_needs_network'));
                }
                if ($this->input('provider_mode') === 'new' && $this->input('network_mode') === 'existing') {
                    $validator->errors()->add('cafeteria_service_network_id', __('cafeteria-policy.validation.network_not_in_provider'));
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => __('cafeteria.providerCode'),
            'name_en' => __('cafeteria.nameEn'),
            'name_am' => __('cafeteria.nameAm'),
        ];
    }
}
