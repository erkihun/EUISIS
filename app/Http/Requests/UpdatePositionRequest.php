<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOrganizationWithinScope;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    use ValidatesOrganizationWithinScope;

    public function authorize(): bool
    {
        /** @var Position $position */
        $position = $this->route('position');

        return $this->user()?->can('update', $position) ?? false;
    }

    public function rules(): array
    {
        /** @var Position $position */
        $position = $this->route('position');

        return [
            'job_position_code' => ['required', 'string', 'max:255', Rule::unique('positions', 'job_position_code')->ignore($position->id)],
            'old_code' => ['nullable', 'string', 'max:100'],
            'title_en' => ['nullable', 'string', 'max:255', 'required_without:title_am'],
            'title_am' => ['nullable', 'string', 'max:255', 'required_without:title_en'],
            'bpr_name' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id', $this->organizationWithinScopeRule()],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id', $this->organizationUnitBelongsToOrganizationRule()],
            'occupation_id' => ['nullable', 'uuid', 'exists:occupations,id'],
            'description_en' => ['nullable', 'string'],
            'description_am' => ['nullable', 'string'],
            'grade_level' => ['nullable', 'string', 'max:255'],
            'job_family' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
