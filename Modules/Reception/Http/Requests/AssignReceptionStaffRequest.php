<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignReceptionStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reception.configure') ?? false;
    }

    public function rules(): array
    {
        return ['reception_desk_id' => ['required', 'uuid', 'exists:reception_desks,id'], 'user_id' => ['required', 'integer', 'exists:users,id'], 'role' => ['required', Rule::in(['administrator', 'supervisor', 'officer', 'information_officer'])], 'is_active' => ['sometimes', 'boolean']];
    }
}
