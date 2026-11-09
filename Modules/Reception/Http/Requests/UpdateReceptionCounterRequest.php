<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateReceptionCounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.supervise') || $this->user()?->can('reception.configure');
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['open', 'paused', 'closed'])], 'assigned_user_id' => ['nullable', 'integer', 'exists:users,id']];
    }
}
