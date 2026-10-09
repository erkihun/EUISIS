<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ProviderPasswordResetCode;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProviderPasswordResetCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('provider-portal.reset_code_subject'))
            ->greeting(__('provider-portal.reset_code_greeting'))
            ->line(__('provider-portal.reset_code_intro'))
            ->line('**'.$this->code.'**')
            ->line(__('provider-portal.reset_code_expiry', ['minutes' => ProviderPasswordResetCode::TTL_MINUTES]))
            ->line(__('provider-portal.reset_code_ignore'));
    }

    public function toSmsText(): string
    {
        return __('provider-portal.reset_code_sms', [
            'code' => $this->code,
            'minutes' => ProviderPasswordResetCode::TTL_MINUTES,
        ]);
    }
}
