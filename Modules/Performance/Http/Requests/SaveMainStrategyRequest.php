<?php

declare(strict_types=1);

namespace Modules\Performance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A main strategy with its strategies, entered on one form (ADR 0019). */
class SaveMainStrategyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $weight = ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'];

        return [
            'cycle_id' => [$creating ? 'required' : 'prohibited', 'uuid', 'exists:performance_cycles,id'],
            'organization_id' => [$creating ? 'required' : 'prohibited', 'uuid', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'weight_percent' => $weight,
            'strategies' => ['required', 'array', 'min:1', 'max:50'],
            'strategies.*.id' => ['nullable', 'uuid'],
            'strategies.*.title' => ['required', 'string', 'max:500'],
            'strategies.*.description' => ['nullable', 'string', 'max:2000'],
            'strategies.*.weight_percent' => $weight,
        ];
    }

    public function attributes(): array
    {
        return [
            'cycle_id' => __('performance.attributes.cycle'),
            'organization_id' => __('performance.attributes.organization'),
            'title' => __('performance.hierarchy.attributes.main_strategy'),
            'description' => __('performance.hierarchy.attributes.description'),
            'weight_percent' => __('performance.attributes.weight'),
            'strategies' => __('performance.hierarchy.attributes.strategies'),
            'strategies.*.title' => __('performance.hierarchy.attributes.strategy'),
            'strategies.*.description' => __('performance.hierarchy.attributes.description'),
            'strategies.*.weight_percent' => __('performance.attributes.weight'),
        ];
    }
}
