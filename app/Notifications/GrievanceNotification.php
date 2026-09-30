<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Grievance notifications. Rendered at read time in the reader's language by
 * NotificationPresenter. By design they carry only the case number and what
 * kind of action is needed — never the subject, description, decision text
 * or any other confidential content (docs/grievance-management.md §11).
 */
class GrievanceNotification extends Notification
{
    /** @param list<string> $channels */
    public function __construct(
        public string $kind,
        public string $caseNumber,
        public ?string $url,
        public array $channels,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'grievance', 'kind' => $this->kind, 'case_number' => $this->caseNumber, 'url' => $this->url];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');
        $case = (string) ($data['case_number'] ?? '');
        $key = "grievances.notifications.{$kind}";
        $message = (string) __($key, ['case' => $case], $locale);

        return [
            'title' => (string) __('grievances.notifications.title', [], $locale),
            'message' => $message === $key ? (string) __('grievances.notifications.generic', ['case' => $case], $locale) : $message,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->toArray($notifiable), null);
        $mail = (new MailMessage)->subject($text['title'].' — '.$this->caseNumber)->line($text['message']);

        return $this->url !== null ? $mail->action((string) __('grievances.notifications.open'), url($this->url)) : $mail;
    }
}
