<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your password was changed / reset." Never contains the password, a hash,
 * a token or a link that signs anyone in: only what happened, when, and what
 * to do if it was not them.
 *
 * Employees and administrators get it in the notification bell and by email;
 * provider accounts by email (they have no bell).
 */
class PasswordSecurityNotification extends Notification
{
    public function __construct(public string $kind, public string $occurredAt) {}

    public function via(object $notifiable): array
    {
        $channels = $notifiable instanceof User ? ['database'] : [];
        if (filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return ['module' => 'account_security', 'kind' => $this->kind, 'occurred_at' => $this->occurredAt, 'url' => null];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = in_array($data['kind'] ?? '', ['changed', 'reset', 'admin_reset'], true) ? $data['kind'] : 'changed';

        return [
            'title' => (string) __("password-policy.notification.{$kind}.title", [], $locale),
            'message' => (string) __('password-policy.notification.body', ['time' => (string) ($data['occurred_at'] ?? '')], $locale)
                .' '.(string) __('password-policy.notification.not_you', [], $locale),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $text = self::render($this->toArray($notifiable), null);

        return (new MailMessage)
            ->subject($text['title'])
            ->line(__('password-policy.notification.account', ['account' => (string) ($notifiable->email ?? '')]))
            ->line(__('password-policy.notification.body', ['time' => $this->occurredAt]))
            ->line(__('password-policy.notification.not_you'));
    }
}
