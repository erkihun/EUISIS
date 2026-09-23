<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationalChange;

use App\Enums\OrganizationalChangeRequestType;
use App\Models\OrganizationalChangeRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape-only validation. The organization id and every entity reference are
 * re-resolved and scope-checked server-side by ChangeRequestScopeService, so
 * passing an id here proves nothing.
 */
class StoreOrganizationalChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationalChangeRequest::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid'],
            'request_type' => ['required', Rule::in(OrganizationalChangeRequestType::values())],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'requested_effective_date' => ['nullable', 'date'],
            'payload' => ['required', 'array'],
        ];
    }
}
