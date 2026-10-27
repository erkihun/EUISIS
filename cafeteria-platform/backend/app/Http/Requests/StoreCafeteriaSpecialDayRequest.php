<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CafeteriaSpecialDayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCafeteriaSpecialDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cafeteria_special_days.create');
    }

    public function rules(): array
    {
        return [
            'special_date' => ['required', 'date'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'day_type' => ['required', Rule::in(array_column(CafeteriaSpecialDayType::cases(), 'value'))],
            'is_open' => ['required', 'boolean'],
            'is_subsidy_day' => ['required', 'boolean'],
            'cafeteria_provider_id' => ['nullable', 'uuid', 'exists:cafeteria_providers,id'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i', 'after:open_time'],
            'reason_en' => ['nullable', 'string', 'max:2000'],
            'reason_am' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
