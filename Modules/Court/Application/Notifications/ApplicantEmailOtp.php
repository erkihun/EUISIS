<?php

declare(strict_types=1);

namespace Modules\Court\Application\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApplicantEmailOtp extends Notification
{
    public function __construct(protected readonly string $code) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Email Verification Code')
            ->greeting('Hello '.($notifiable->full_name ?? 'Applicant').',')
            ->line('Use the 6-digit code below to verify your email address:')
            ->line('# '.$this->code)
            ->line('This code expires in **10 minutes**.')
            ->line('If you did not create an account, no further action is required.');
    }
}
