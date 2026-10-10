<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * EPMS notifications (plan assigned, agreement ready/returned/approved,
 * reviews due/returned, result released, appeal decided...). Rendered at
 * read time in the reader's language by NotificationPresenter; never carries
 * scores, ratings or comments — only what happened and where to look.
 */
class PerformanceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $channels */
    public function __construct(public string $kind, public ?string $url, public array $channels)
    {
        // Delivered by the queue workers, not inside the request (INF-3).
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'performance', 'kind' => $this->kind, 'url' => $this->url];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');

        return [
            'title' => (string) __('performance.notifications.title', [], $locale),
            'message' => (string) __("performance.notifications.{$kind}", [], $locale),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->toArray($notifiable), null);

        return (new MailMessage)->subject($text['title'])->line($text['message']);
    }
}
