<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The receipt a member of the public gets by e-mail after submitting, when
 * the policy allows it (brief §89): type, reference and time, in the
 * language they used. Never the text they wrote, never a link into the
 * system — there is no public tracking (§41).
 */
class PublicFeedbackAcknowledgement extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $submissionType,
        public ?string $reference,
        public string $submittedAt,
        public string $language = 'en',
    ) {
        $this->onQueue('notifications');
        // Laravel renders the notification in this locale when it sends.
        $this->locale($language);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = (string) __('service-feedback.types.'.$this->submissionType, [], $this->language);
        $mail = (new MailMessage)
            ->subject((string) __('service-feedback.acknowledgement.subject', ['type' => $type], $this->language))
            ->greeting((string) __('service-feedback.acknowledgement.greeting', [], $this->language))
            ->line((string) __('service-feedback.acknowledgement.received', ['type' => $type, 'time' => $this->submittedAt], $this->language));

        if ($this->reference !== null && $this->reference !== '') {
            $mail->line((string) __('service-feedback.acknowledgement.reference', ['reference' => $this->reference], $this->language));
        }

        return $mail->line((string) __('service-feedback.acknowledgement.no_tracking', [], $this->language));
    }
}
