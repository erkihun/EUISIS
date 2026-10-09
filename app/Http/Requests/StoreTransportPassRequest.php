<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransportPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transport-passes.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'provider_id' => ['required', 'uuid', 'exists:providers,id'],
            'transport_route_id' => ['nullable', 'uuid', 'exists:transport_routes,id'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::in(['active', 'suspended', 'expired', 'cancelled'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
