<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Public feedback notices for staff (brief §88), rendered at read time in
 * the reader's language by NotificationPresenter. They carry only the
 * submission type, a reference and a portal link — never the text, the
 * target or the submitter — so a mail copy leaks nothing.
 */
class PublicFeedbackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $channels */
    public function __construct(
        public string $kind,
        public string $submissionType,
        public string $reference,
        public ?string $url,
        public array $channels = ['database'],
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'public_feedback',
            'kind' => $this->kind,
            'submission_type' => $this->submissionType,
            'reference' => $this->reference,
            'url' => $this->url,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->toArray($notifiable), null);
        $mail = (new MailMessage)->subject($text['title'].' — '.$this->reference)->line($text['message']);

        return $this->url !== null ? $mail->action((string) __('service-feedback.notifications.open'), url($this->url)) : $mail;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');
        $type = (string) __('service-feedback.types.'.($data['submission_type'] ?? 'complaint'), [], $locale);
        $reference = (string) ($data['reference'] ?? '');

        return [
            'title' => (string) __('service-feedback.notifications.'.$kind.'_title', [], $locale),
            'message' => (string) __('service-feedback.notifications.'.$kind, ['type' => $type, 'reference' => $reference], $locale),
        ];
    }
}
