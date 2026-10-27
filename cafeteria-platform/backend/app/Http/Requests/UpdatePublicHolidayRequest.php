<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PublicHoliday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePublicHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $holiday = $this->route('publicHoliday');

        return $holiday instanceof PublicHoliday
            ? ($this->user()?->can('update', $holiday) ?? false)
            : false;
    }

    public function rules(): array
    {
        $holidayId = $this->route('publicHoliday')?->id;

        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'holiday_date' => ['required', 'date', Rule::unique('public_holidays', 'holiday_date')->ignore($holidayId)],
            'is_recurring' => ['boolean'],
            'recurrence_type' => ['nullable', 'in:gregorian,ethiopian', 'required_if:is_recurring,true'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'region' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
