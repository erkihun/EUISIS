<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationalChange;

use Illuminate\Foundation\Http\FormRequest;

class AssignImplementationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assignImplementation', $this->route('organizationalChangeRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'implementation_assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'implementing_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
        ];
    }
}
