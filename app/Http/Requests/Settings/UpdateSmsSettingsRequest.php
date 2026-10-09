<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSmsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageSms') ?? false;
    }

    public function rules(): array
    {
        return [
            'sms_provider' => ['required', Rule::in(['disabled', 'generic_http', 'local_gateway', 'ethio_telecom', 'custom'])],
            'sms_api_url' => ['nullable', 'url', 'max:500'],
            'sms_api_key' => ['nullable', 'string', 'max:500'],
            'sms_sender_id' => ['nullable', 'string', 'max:40'],
            'sms_default_country_code' => ['nullable', 'string', 'max:8', 'regex:/^\\+?[0-9]{1,7}$/'],
            'sms_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'sms_test_phone' => ['nullable', 'string', 'max:40'],
            'sms_rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
