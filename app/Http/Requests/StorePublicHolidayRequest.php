<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PublicHoliday;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PublicHoliday::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'holiday_date' => ['required', 'date', 'unique:public_holidays,holiday_date'],
            'is_recurring' => ['boolean'],
            'recurrence_type' => ['nullable', 'in:gregorian,ethiopian', 'required_if:is_recurring,true'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'region' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
