<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOrganizationWithinScope;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    use ValidatesOrganizationWithinScope;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Position::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'job_position_code' => ['nullable', 'string', 'max:255', 'unique:positions,job_position_code'],
            'old_code' => ['nullable', 'string', 'max:100'],
            'title_en' => ['required', 'string', 'max:255'],
            'title_am' => ['required', 'string', 'max:255'],
            'bpr_name' => ['nullable', 'string', 'max:255'],
            // A position with no unit is organization-level (the organization's head, advisers): it then needs the organization.
            'organization_id' => ['nullable', 'required_without:organization_unit_id', 'uuid', 'exists:organizations,id', $this->organizationWithinScopeRule()],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id', $this->organizationUnitBelongsToOrganizationRule()],
            'occupation_id' => ['required', 'uuid', 'exists:occupations,id'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'grade_level' => ['nullable', 'string', 'max:255', Rule::exists('grade_levels', 'name')->whereNull('deleted_at')],
            'job_family' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'is_unit_head' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
