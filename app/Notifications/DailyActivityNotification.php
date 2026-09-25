<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminders and workflow notices for the Daily Activity Register.
 *
 * Kinds: end_of_day, next_day, returned, reopened, approved. Channels follow
 * the existing System Settings > Notifications switches, resolved by
 * DailyActivityNotifier before this is constructed.
 */
class DailyActivityNotification extends Notification
{
    use Queueable;

    /** @param array<int, string> $channels */
    public function __construct(
        public readonly string $kind,
        public readonly string $activityDate,
        public readonly ?string $comment,
        public readonly array $channels,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'daily_activity',
            'kind' => $this->kind,
            'activity_date' => $this->activityDate,
            'comment' => $this->comment,
            'title' => $this->subject(),
            'message' => $this->body(),
            'url' => route('employee.daily-activity.entry', ['date' => $this->activityDate], false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->body())
            ->action(__('daily-activities.notifications.action'), route('employee.daily-activity.entry', ['date' => $this->activityDate]));
    }

    private function subject(): string
    {
        return self::render(['kind' => $this->kind, 'activity_date' => $this->activityDate, 'comment' => $this->comment], null)['title'];
    }

    private function body(): string
    {
        return self::render(['kind' => $this->kind, 'activity_date' => $this->activityDate, 'comment' => $this->comment], null)['message'];
    }

    /**
     * Title and message for a stored notification, in the READER's language.
     * The database keeps the kind and parameters, so an Amharic reader sees
     * Amharic even when the notice was sent while the server spoke English.
     *
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    public static function render(array $data, ?string $locale): array
    {
        $kind = (string) ($data['kind'] ?? '');

        return [
            'title' => (string) __("daily-activities.notifications.{$kind}_subject", [], $locale),
            'message' => (string) __("daily-activities.notifications.{$kind}_body", [
                'date' => (string) ($data['activity_date'] ?? ''),
                'comment' => (string) ($data['comment'] ?? ''),
            ], $locale),
        ];
    }
}
