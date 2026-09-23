<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationalChange;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a draft or a correction. The request type and organization are not
 * accepted here: both are fixed once the request exists, so the review trail
 * always refers to the same subject.
 */
class UpdateOrganizationalChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('organizationalChangeRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'requested_effective_date' => ['nullable', 'date'],
            'payload' => ['required', 'array'],
        ];
    }
}
