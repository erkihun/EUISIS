<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocalizationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageLocalization') ?? false;
    }

    public function rules(): array
    {
        $locales = ['en', 'am'];

        return [
            'default_locale' => ['required', Rule::in($locales)],
            'fallback_locale' => ['required', Rule::in($locales)],
            'supported_locales' => ['required', 'array', 'min:1'],
            'supported_locales.*' => ['string', Rule::in($locales)],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'date_format' => ['required', 'string', 'max:32'],
            'datetime_format' => ['required', 'string', 'max:32'],
            'first_day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'number_format' => ['nullable', 'string', 'max:32'],
            'organization_name_display' => ['nullable', 'string', Rule::in(['english', 'amharic', 'both'])],
            'employee_name_display' => ['nullable', 'string', Rule::in(['full_name', 'first_last'])],
            /*
             * Drives which calendar the whole application renders — read by
             * HandleInertiaRequests on every request. It was registered and
             * shown in the form but had no rule here, so `validated()` dropped
             * it and an administrator could never actually leave `locale_based`.
             */
            'calendar_system_mode' => ['required', Rule::in(['locale_based', 'gregorian_only', 'ethiopian_only'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('supported_locales') && $this->has('enabled_locales')) {
            $this->merge([
                'supported_locales' => $this->input('enabled_locales'),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $supported = (array) $this->input('supported_locales', []);

            if (! in_array($this->input('default_locale'), $supported, true)) {
                $v->errors()->add('default_locale', 'Default locale must be one of the supported locales.');
            }

            if (! in_array($this->input('fallback_locale'), $supported, true)) {
                $v->errors()->add('fallback_locale', 'Fallback locale must be one of the supported locales.');
            }
        });
    }
}
