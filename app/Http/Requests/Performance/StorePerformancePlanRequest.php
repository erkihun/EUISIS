<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use App\Enums\Performance\PlanType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shape validation only; authorization and business rules live in the EPMS services. */
class StorePerformancePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authority (scope, manager, workflow state) is enforced by the service.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'cycle_id' => ['required', 'uuid', 'exists:performance_cycles,id'],
            'plan_type' => ['required', Rule::enum(PlanType::class)],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'position_id' => ['nullable', 'uuid', 'exists:positions,id'],
            'parent_plan_id' => ['nullable', 'uuid', 'exists:performance_plans,id'],
            'title' => ['required', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
