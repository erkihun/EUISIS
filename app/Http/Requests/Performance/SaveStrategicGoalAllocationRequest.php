<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use App\Enums\Performance\GoalAllocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStrategicGoalAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'organization_unit_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid', 'exists:organization_units,id'],
            'organization_contribution_percent' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'allocation_type' => ['required', Rule::enum(GoalAllocationType::class)],
            'is_lead' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Field names inside validation messages, in the viewer's language. */
    public function attributes(): array
    {
        return [
            'organization_unit_id' => __('performance.attributes.unit'),
            'organization_contribution_percent' => __('performance.attributes.contribution'),
            'allocation_type' => __('performance.attributes.allocation_type'),
            'is_lead' => __('performance.attributes.lead'),
            'notes' => __('performance.attributes.notes'),
        ];
    }
}
