<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageTelegram') ?? false;
    }

    public function rules(): array
    {
        return [
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_default_chat_id' => ['nullable', 'string', 'max:64'],
            'telegram_webhook_url' => ['nullable', 'url', 'max:255'],
            'telegram_notifications_channel' => ['nullable', 'string', 'max:64'],
            'telegram_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'telegram_test_chat_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
