<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCafeteriaDayRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cafeteria_day_rules.update');
    }

    public function rules(): array
    {
        return [
            'is_open' => ['required', 'boolean'],
            'is_subsidy_day' => ['required', 'boolean'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i', 'after:open_time'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
