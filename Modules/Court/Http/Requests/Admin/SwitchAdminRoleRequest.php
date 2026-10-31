<?php

declare(strict_types=1);

namespace Modules\Court\Http\Requests\Admin;

use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchAdminRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_id' => [
                'required',
                'integer',
                Rule::exists('court_role_user', 'role_id')
                    ->where('user_id', $this->user()?->id),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role_id.required' => __('court::app.role_switch_required'),
            'role_id.exists' => __('court::app.role_switch_invalid'),
        ];
    }
}
