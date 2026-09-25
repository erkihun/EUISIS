<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use App\Services\Performance\EmployeeAgreementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shape validation only; authorization and business rules live in the EPMS services. */
class SaveAgreementItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authority (scope, manager, workflow state) is enforced by the service.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'kpi_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid', 'exists:kpis,id'],
            'objective_id' => ['nullable', 'uuid', 'exists:performance_objectives,id'],
            'position_target_id' => ['nullable', 'uuid', 'exists:kpi_targets,id'],
            'expected_output' => ['required', 'string', 'max:500'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'baseline_value' => ['nullable', 'numeric'],
            'target_value' => ['nullable', 'numeric'],
            'target_numerator' => ['nullable', 'numeric', 'required_with:target_denominator'],
            'target_denominator' => ['nullable', 'numeric', 'not_in:0', 'required_with:target_numerator'],
            'achievement_cap' => ['nullable', 'numeric', 'min:100', 'max:200'],
            'tolerance' => ['nullable', 'numeric', 'min:0'],
            'zero_score_deviation' => ['nullable', 'numeric', 'gt:0'],
            'data_source_type' => ['nullable', Rule::in(EmployeeAgreementService::itemSources())],
        ];
    }
}
