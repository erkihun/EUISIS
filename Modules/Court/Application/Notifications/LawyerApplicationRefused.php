<?php

declare(strict_types=1);

namespace Modules\Court\Application\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The registry refused a lawyer's application; the email gives its reason. */
class LawyerApplicationRefused extends Notification
{
    public function __construct(private readonly string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('court::lawyer.apply.refused_mail.subject'))
            ->line(__('court::lawyer.apply.refused_mail.intro'))
            ->line(__('court::lawyer.apply.refused_mail.reason', ['reason' => $this->reason]))
            ->line(__('court::lawyer.apply.refused_mail.outro'));
    }
}
