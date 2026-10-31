<?php

declare(strict_types=1);

namespace Modules\Performance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A strategic goal with its objectives and activities, entered on one form (ADR 0019). */
class SaveStrategyGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $weight = ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'];

        return [
            'strategy_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'uuid', 'exists:performance_strategies,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'objectives' => ['required', 'array', 'min:1', 'max:50'],
            'objectives.*.id' => ['nullable', 'uuid'],
            'objectives.*.title' => ['required', 'string', 'max:500'],
            'objectives.*.weight_percent' => $weight,
            'objectives.*.activities' => ['required', 'array', 'min:2', 'max:100'],
            'objectives.*.activities.*.id' => ['nullable', 'uuid'],
            'objectives.*.activities.*.title' => ['required', 'string', 'max:500'],
            'objectives.*.activities.*.weight_percent' => $weight,
            'objectives.*.activities.*.time_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'strategy_id' => __('performance.hierarchy.attributes.strategy'),
            'description' => __('performance.hierarchy.attributes.description'),
            'objectives' => __('performance.hierarchy.attributes.objectives'),
            'objectives.*.title' => __('performance.hierarchy.attributes.objective'),
            'objectives.*.weight_percent' => __('performance.attributes.weight'),
            'objectives.*.activities' => __('performance.hierarchy.attributes.activities'),
            'objectives.*.activities.*.title' => __('performance.hierarchy.attributes.activity'),
            'objectives.*.activities.*.weight_percent' => __('performance.attributes.weight'),
            'objectives.*.activities.*.time_minutes' => __('performance.hierarchy.attributes.time_minutes'),
        ];
    }
}
