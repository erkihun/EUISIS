<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageNotifications') ?? false;
    }

    public function rules(): array
    {
        return [
            'database_notifications_enabled' => ['required', 'boolean'],
            'email_notifications_enabled' => ['required', 'boolean'],
            'sms_notifications_enabled' => ['required', 'boolean'],
            'telegram_notifications_enabled' => ['required', 'boolean'],
            'notification_retry_attempts' => ['nullable', 'integer', 'min:0', 'max:10'],
            'notification_queue_name' => ['nullable', 'string', 'max:80'],
            'notify_admin_on_security_event' => ['required', 'boolean'],
            'notify_user_on_card_ready' => ['required', 'boolean'],
            'notify_user_on_transfer_approved' => ['required', 'boolean'],
        ];
    }
}
