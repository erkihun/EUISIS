<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use Illuminate\Foundation\Http\FormRequest;

class SaveStrategicGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'cycle_id' => [$creating ? 'required' : 'prohibited', 'uuid', 'exists:performance_cycles,id'],
            'organization_id' => [$creating ? 'required' : 'prohibited', 'uuid', 'exists:organizations,id'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:50', 'alpha_dash'],
            'name_en' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'name_am' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'weight_percent' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'is_shared' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
