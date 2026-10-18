<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use App\Enums\Communication\AccessScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An investigation access request: the scope, a substantial written reason and
 * a case reference are required (authorization: RequestInvestigationAccessAction).
 */
class StoreAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->checkPermissionTo('communication.break_glass.request');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::in(AccessScopeType::values())],
            'conversation_id' => ['nullable', 'required_if:scope_type,CONVERSATION', 'uuid'],
            'target_user_id' => ['nullable', 'required_if:scope_type,EMPLOYEE', 'integer'],
            'reason' => ['required', 'string', 'min:30', 'max:4000'],
            'case_reference' => ['required', 'string', 'max:100'],
            'hours' => ['required', 'integer', 'min:1', 'max:72'],
        ];
    }
}
