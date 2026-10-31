<?php

declare(strict_types=1);

namespace Modules\Court\Application\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordResetOtp extends Notification
{
    public function __construct(protected readonly string $code) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $name = $notifiable->full_name ?? $notifiable->name ?? 'User';

        return (new MailMessage)
            ->subject('Password Reset Code')
            ->greeting('Hello '.$name.',')
            ->line('You requested to reset your password. Use the code below:')
            ->line('# '.$this->code)
            ->line('This code expires in **10 minutes**.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
