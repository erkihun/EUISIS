<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class TestNotificationChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.testNotificationChannels') ?? false;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'chat_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
