<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransportProviderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('assigned_scope_type') === 'citywide') {
            $this->merge(['assigned_organization_id' => null]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('transport-providers.update') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $provider = $this->route('provider');
        $providerId = is_object($provider) ? $provider->id : $provider;

        return [
            'provider_code' => ['required', 'string', 'max:50', Rule::unique('providers', 'provider_code')->ignore($providerId)],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'assigned_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'assigned_scope_type' => ['required', Rule::in(['self', 'subtree', 'citywide'])],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'service_area_description_en' => ['nullable', 'string'],
            'service_area_description_am' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ];
    }
}
