<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use App\Enums\Performance\ObjectiveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shape validation only; authorization and business rules live in the EPMS services. */
class SaveObjectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authority (scope, manager, workflow state) is enforced by the service.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'strategic_goal_id' => ['nullable', 'uuid', 'exists:strategic_goals,id'],
            'code' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:50', 'alpha_dash'],
            'title_en' => ['required', 'string', 'max:500'],
            'title_am' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'objective_type' => ['sometimes', Rule::enum(ObjectiveType::class)],
            'is_mandatory' => ['sometimes', 'boolean'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'absolute_weight_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'local_weight_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }
}
