<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CodeRuleEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewCodeForEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user who can create any entity may preview codes.
        // The generic permission code-rules.preview is the baseline; entity-specific
        // create permissions are also accepted so HR officers can preview without
        // needing explicit code-rules.preview.
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('code-rules.preview')
            || $user->can('organizations.manage')
            || $user->can('organization-units.create')
            || $user->can('employees.manage')
            || $user->can('positions.create')
            || $user->can('service-types.create')
            || $user->can('organization-types.create');
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::enum(CodeRuleEntityType::class)],
            'scope_type' => ['nullable', 'string', 'max:64'],
            'scope_id' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', 'array'],
            'context.organization_type_id' => ['nullable', 'string', 'max:255'],
            'context.organization_id' => ['nullable', 'string', 'max:255'],
            'context.organization_unit_id' => ['nullable', 'string', 'max:255'],
            'context.organization_unit_type_id' => ['nullable', 'string', 'max:255'],
            'context.service_type_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
