<?php

namespace Modules\Court\Application\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * The emailed link that sets or resets an account's password. A lawyer's opens
 * the lawyers' page (/lawyer/reset-password); the registry's new lawyer accounts
 * get the same link to choose their first password.
 */
class ApplicantResetPassword extends Notification
{
    public function __construct(protected string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $parameters = ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()];
        $lawyer = (bool) ($notifiable->is_lawyer ?? false) || ! Route::has('applicant.password.reset');
        $url = $lawyer ? route('court.lawyer.password.reset', $parameters) : route('applicant.password.reset', $parameters);

        return (new MailMessage)
            ->subject(__('court::lawyer.mail.subject'))
            ->line(__('court::lawyer.mail.intro'))
            ->action(__('court::lawyer.mail.action'), $url)
            ->line(__('court::lawyer.mail.outro'));
    }
}
