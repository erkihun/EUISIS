<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EntitlementStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('entitlements.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'service_provider_id' => ['nullable', 'uuid', 'exists:service_providers,id'],
            'quota_limit' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
