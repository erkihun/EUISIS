<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A follow-up action of a visit. */
final class CreateActionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('visit'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'finding_id' => ['nullable', 'uuid'],
            'owner_organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
