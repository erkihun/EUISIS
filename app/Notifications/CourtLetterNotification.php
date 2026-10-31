<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A letter from the administrative tribunal reached the reader's institution
 * (court-ms Flow 5). Rendered at read time in the reader's language by
 * NotificationPresenter; it names the case number only, never the letter's
 * subject or content.
 */
class CourtLetterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $caseNumber,
        public string $url,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'court_letter', 'kind' => 'received', 'case_number' => $this->caseNumber, 'url' => $this->url];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $case = (string) ($data['case_number'] ?? '');

        return [
            'title' => (string) __('notifications.events.court_letter_received.title', [], $locale),
            'message' => (string) __('notifications.events.court_letter_received.body', ['case' => $case], $locale),
        ];
    }
}
