<?php

declare(strict_types=1);

namespace App\Notifications\Communication;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Communication notifications (docs/communication-module.md §Notifications).
 *
 * Chat notifies in-app only and carries no message text — the reader opens the
 * conversation, where membership is checked. Only official announcements of
 * high priority may also use e-mail, when the system and the reader allow it.
 * Wording is rendered at read time in the reader's language.
 */
class CommunicationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KINDS = [
        'direct_message', 'mentioned', 'added_to_group', 'removed_from_group', 'channel_invitation',
        'announcement_important', 'announcement_urgent', 'announcement_ack_required', 'task_assigned', 'access_requested', 'access_approved', 'access_rejected',
    ];

    /**
     * @param  array<string, string|int|null>  $params  names and titles for the message (no message bodies)
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $kind,
        public array $params,
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
        return ['module' => 'communication', 'kind' => $this->kind, 'params' => $this->params, 'url' => $this->url];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = in_array($data['kind'] ?? null, self::KINDS, true) ? $data['kind'] : 'generic';
        $params = array_map(fn ($v) => (string) $v, (array) ($data['params'] ?? []));
        // Announcements carry both titles; Amharic readers get the Amharic one.
        if ($locale === 'am' && filled($params['title_am'] ?? null)) {
            $params['title'] = $params['title_am'];
        }

        return [
            'title' => (string) __("communication.notifications.{$kind}.title", $params, $locale),
            'message' => (string) __("communication.notifications.{$kind}.message", $params, $locale),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->toArray($notifiable), $notifiable->preferredLocale() ?? null);
        $mail = (new MailMessage)->subject($text['title'])->line($text['message']);

        return $this->url !== null ? $mail->action((string) __('communication.notifications.open'), url($this->url)) : $mail;
    }
}
