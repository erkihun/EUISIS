<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A minimal, non-sensitive notice; eligibility is established before queueing. */
class TransferAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $channels */
    public function __construct(public readonly string $announcementId, public readonly array $channels) {}

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'transfer',
            'kind' => 'announcement_published',
            'url' => '/my-portal/announcements/transfer/'.$this->announcementId,
        ];
    }

    /** @return array{title: string, message: string} */
    public static function render(array $data, ?string $locale): array
    {
        return [
            'title' => (string) __('transfers.announcements', [], $locale),
            'message' => (string) __('transfers.announcementPublished', [], $locale),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render([], null);

        return (new MailMessage)->subject($text['title'])->line($text['message']);
    }
}
