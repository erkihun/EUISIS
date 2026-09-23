<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\EmployeeRegistrationOtp;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeRegistrationOtpNotification extends Notification
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
            ->subject(__('auth.registration_otp_subject'))
            ->greeting(__('auth.registration_otp_greeting'))
            ->line(__('auth.registration_otp_intro'))
            ->line('**'.$this->code.'**')
            ->line(__('auth.registration_otp_expiry', ['minutes' => EmployeeRegistrationOtp::TTL_MINUTES]))
            ->line(__('auth.registration_otp_ignore'));
    }

    public function toSmsText(): string
    {
        return __('auth.registration_otp_sms', [
            'code' => $this->code,
            'minutes' => EmployeeRegistrationOtp::TTL_MINUTES,
        ]);
    }
}
