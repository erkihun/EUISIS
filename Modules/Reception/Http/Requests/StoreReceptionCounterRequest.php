<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReceptionCounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.configure') ?? false;
    }

    public function rules(): array
    {
        return [
            'reception_desk_id' => ['required', 'uuid', 'exists:reception_desks,id'], 'code' => ['required', 'string', 'max:40'],
            'name_en' => ['required', 'string', 'max:255'], 'name_am' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['open', 'paused', 'closed'])], 'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
